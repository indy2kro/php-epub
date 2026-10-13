<?php

declare(strict_types=1);

namespace PhpEpub\Split;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpEpub\BookTemplate;
use PhpEpub\Cleanup\ReferenceAnalysis;
use PhpEpub\Cleanup\ReferenceGraph;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\FontObfuscation;
use PhpEpub\Landmark;
use PhpEpub\ManifestItem;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\LinkRemover;
use PhpEpub\Util\ModifiedDate;
use PhpEpub\Util\PathResolver;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;

/**
 * Cuts a book into several books by reading order (see SplitPlan).
 *
 * Each part is a copy of the book that keeps only its own reading order items and what they need: the images,
 * stylesheets, fonts and other files they use (found with ReferenceGraph), the navigation document, the NCX and
 * the cover. The parts keep the book's metadata, with a new unique identifier each (other identifiers such as the
 * ISBN are dropped, as they identify the whole book) and the title from SplitPlan::withTitlePattern(). Obfuscated
 * fonts are re-keyed to the new identifier.
 *
 * The navigation document, the NCX and the landmarks keep only the entries that point into the part; an entry for
 * another part is dropped, and a heading whose own page is elsewhere stays as an unlinked heading when part of its
 * children are here. A page list keeps the pages of the part's documents. A link in a document to a document of another part becomes plain text
 * (the link element is replaced by its content; a <link> element to it is removed). Documents that are not
 * well-formed are left as they are.
 *
 * The book is only read; nothing on its disk changes (unsaved edits are included).
 */
final readonly class Splitter
{
    /**
     * @param XmlParser|null $xmlParser The parser for the book's documents; by default the book's own, which keeps
     *                                  the Limits it was opened with.
     */
    public function __construct(
        private PathResolver $paths = new PathResolver(),
        private ?XmlParser $xmlParser = null
    ) {
    }

    /**
     * Writes the parts into a directory (created when missing).
     *
     * @return list<string> The paths of the parts, in order.
     *
     * @throws Exception If the book is not loaded or DRM-protected, the plan does not fit the book (a range beyond
     *                   the reading order, no table of contents entries to split at), a part file already exists, or a
     *                   part cannot be written.
     */
    public function split(EpubFile $book, SplitPlan $plan, string $outputDirectory): array
    {
        return $this->splitWithReport($book, $plan, $outputDirectory)->paths;
    }

    /**
     * Like split(), and reports what could not be read from the book (see SplitReport::$warnings).
     *
     * @throws Exception As split() does.
     */
    public function splitWithReport(EpubFile $book, SplitPlan $plan, string $outputDirectory): SplitReport
    {
        $warnings = [];
        $directory = $book->getTempDir() ?? throw new Exception('EPUB file must be loaded before splitting.');
        $book->isDrmProtected() && throw new Exception('The book is DRM-protected, so its content cannot be split.');

        $manifest = $book->getManifest();
        $idrefs = $book->getSpine()->get();
        /** @var array<int, string> $spinePaths */
        $spinePaths = [];
        foreach ($idrefs as $position => $idref) {
            $item = $manifest->get($idref);
            if ($item instanceof ManifestItem && $item->path !== '' && is_file($this->paths->resolve($directory, $item->path))) {
                $spinePaths[$position] = $item->path;
            }
        }

        $spinePaths !== [] || throw new Exception('The book has no content to split');

        $toc = $this->tableOfContents($book, $warnings);
        $landmarks = $this->landmarks($book, $warnings);
        $analysis = ReferenceGraph::forBook($book, $this->xmlParser)->analyzeFrom($this->roots($book, $spinePaths));
        $groups = $this->groups($toc, $plan, $spinePaths, $analysis, $directory);
        $total = count($groups);
        $total <= $plan->maxParts || throw new Exception("The split plan would produce {$total} parts; the limit is {$plan->maxParts}");

        // A part file that exists is never overwritten: it could be someone's book, and a failed run would delete it.
        $width = max(2, strlen((string) $total));
        $targets = [];
        foreach (array_keys($groups) as $number) {
            $targets[$number] = rtrim($outputDirectory, '/\\') . DIRECTORY_SEPARATOR . sprintf('%s-%0' . $width . 'd.epub', $plan->filePrefix, $number + 1);
            ! file_exists($targets[$number]) || throw new Exception('Refusing to overwrite an existing file: ' . $targets[$number]);
        }

        is_dir($outputDirectory) || @mkdir($outputDirectory, 0777, true) || is_dir($outputDirectory) || throw new Exception("Failed to create directory: {$outputDirectory}");

        $written = [];

        try {
            foreach ($groups as $number => $positions) {
                $path = $targets[$number];
                $part = $book->copy($this->xmlParser);

                try {
                    $this->buildPart($part, $book, $plan, $positions, $spinePaths, $analysis, $toc, $landmarks, $number + 1, $total, $warnings);
                    // The path did not exist before this run, so whatever is there after a failed save is ours to delete.
                    $written[] = $path;
                    ModifiedDate::save($part, $path, $plan->clock);
                } finally {
                    $part->cleanup();
                }
            }
        } catch (\Throwable $throwable) {
            // No half-finished set of parts is left behind.
            foreach ($written as $path) {
                @unlink($path);
            }

            throw $throwable;
        }

        return new SplitReport($written, $warnings);
    }

    /**
     * What every part might need: the reading order, the navigation document, the NCX and the cover.
     *
     * @param array<int, string> $spinePaths
     *
     * @return list<string>
     */
    private function roots(EpubFile $book, array $spinePaths): array
    {
        return array_values(array_unique([...array_values($spinePaths), ...$this->sharedRoots($book)]));
    }

    /**
     * @return list<string>
     */
    private function sharedRoots(EpubFile $book): array
    {
        $roots = [];
        foreach ($book->getManifest()->getItems() as $item) {
            $properties = explode(' ', $item->properties);
            if ($item->path !== '' && (in_array('nav', $properties, true) || in_array('cover-image', $properties, true) || $item->mediaType === 'application/x-dtbncx+xml')) {
                $roots[] = $item->path;
            }
        }

        $cover = $book->getCoverImage();
        if ($cover instanceof ManifestItem && $cover->path !== '') {
            $roots[] = $cover->path;
        }

        return array_values(array_unique($roots));
    }

    /**
     * The reading order positions of every part.
     *
     * @param list<TocEntry> $toc
     * @param array<int, string> $spinePaths
     *
     * @return list<list<int>>
     *
     * @throws Exception
     */
    private function groups(array $toc, SplitPlan $plan, array $spinePaths, ReferenceAnalysis $analysis, string $directory): array
    {
        $positions = array_keys($spinePaths);

        $groups = match ($plan->kind) {
            SplitKind::Count => array_chunk($positions, max(1, $plan->count)),
            SplitKind::Ranges => $this->rangeGroups($plan, $spinePaths),
            SplitKind::Toc => $this->tocGroups($toc, $plan->level, $spinePaths),
            SplitKind::Bytes => $this->byteGroups($plan->maxBytes, $spinePaths, $analysis, $directory),
        };

        foreach ($groups as $group) {
            $group !== [] || throw new Exception('The split plan would produce an empty part');
        }

        return $groups;
    }

    /**
     * @param array<int, string> $spinePaths
     *
     * @return list<list<int>>
     *
     * @throws Exception
     */
    private function rangeGroups(SplitPlan $plan, array $spinePaths): array
    {
        $length = (int) array_key_last($spinePaths) + 1;
        $groups = [];
        foreach ($plan->ranges as [$start, $end]) {
            $end < $length || throw new Exception("The range [{$start}, {$end}] is beyond the reading order, which has items 0 to " . ($length - 1));
            $group = array_values(array_filter(range($start, $end), static fn (int $position): bool => isset($spinePaths[$position])));
            $group !== [] || throw new Exception("The range [{$start}, {$end}] has no content");
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * @param list<TocEntry> $toc
     * @param array<int, string> $spinePaths
     *
     * @return list<list<int>>
     *
     * @throws Exception
     */
    private function tocGroups(array $toc, int $level, array $spinePaths): array
    {
        $positionOf = [];
        foreach ($spinePaths as $position => $path) {
            $positionOf[$path] ??= $position;
        }

        $starts = [];
        foreach ($this->entriesAtLevel($toc, $level) as $entry) {
            $path = $this->firstPath($entry);
            if ($path !== null && isset($positionOf[$path])) {
                $starts[$positionOf[$path]] = true;
            }
        }

        $starts !== [] || throw new Exception("The table of contents has no entries at level {$level} that point into the reading order");

        $starts = array_keys($starts);
        sort($starts);
        $groups = [];
        foreach ($starts as $index => $start) {
            $end = ($starts[$index + 1] ?? PHP_INT_MAX) - 1;
            $from = $index === 0 ? PHP_INT_MIN : $start;
            $groups[] = array_values(array_filter(array_keys($spinePaths), static fn (int $position): bool => $position >= $from && $position <= $end));
        }

        return $groups;
    }

    /**
     * @param list<TocEntry> $entries
     *
     * @return list<TocEntry>
     */
    private function entriesAtLevel(array $entries, int $level): array
    {
        if ($level <= 1) {
            return $entries;
        }

        $found = [];
        foreach ($entries as $entry) {
            array_push($found, ...$this->entriesAtLevel($entry->children, $level - 1));
        }

        return $found;
    }

    private function firstPath(TocEntry $entry): ?string
    {
        if ($entry->path !== '') {
            return $entry->path;
        }

        foreach ($entry->children as $child) {
            $path = $this->firstPath($child);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $spinePaths
     *
     * @return list<list<int>>
     */
    private function byteGroups(int $maxBytes, array $spinePaths, ReferenceAnalysis $analysis, string $directory): array
    {
        $sizes = [];
        $groups = [];
        $group = [];
        $used = [];
        $total = 0;

        foreach ($spinePaths as $position => $path) {
            $needed = $this->closure([$path], $spinePaths, [$position => true], $analysis);
            $added = 0;
            foreach ($needed as $file => $unused) {
                if (! isset($used[$file])) {
                    $sizes[$file] ??= (int) @filesize($this->paths->resolve($directory, $file));
                    $added += $sizes[$file];
                }
            }

            if ($group !== [] && $total + $added > $maxBytes) {
                $groups[] = $group;
                $group = [];
                $used = [];
                $total = 0;
                $added = 0;
                foreach ($needed as $file => $unused) {
                    $sizes[$file] ??= (int) @filesize($this->paths->resolve($directory, $file));
                    $added += $sizes[$file];
                }
            }

            $group[] = $position;
            $used += $needed;
            $total += $added;
        }

        $groups[] = $group;

        return $groups;
    }

    /**
     * The files reachable from some roots, without following a reference into a reading order document that
     * is not in the part.
     *
     * @param list<string> $roots
     * @param array<int, string> $spinePaths
     * @param array<int, true> $partPositions
     *
     * @return array<string, true>
     */
    private function closure(array $roots, array $spinePaths, array $partPositions, ReferenceAnalysis $analysis): array
    {
        $inSpine = array_flip($spinePaths);
        $inPart = [];
        foreach (array_keys($partPositions) as $position) {
            if (isset($spinePaths[$position])) {
                $inPart[$spinePaths[$position]] = true;
            }
        }

        $reached = [];
        $queue = $roots;
        while ($queue !== []) {
            $path = array_pop($queue);
            if (isset($reached[$path])) {
                continue;
            }

            $reached[$path] = true;
            foreach ($analysis->references[$path] ?? [] as $target) {
                if (! isset($reached[$target]) && (! isset($inSpine[$target]) || isset($inPart[$target]))) {
                    $queue[] = $target;
                }
            }
        }

        return $reached;
    }

    /**
     * @param list<string> $warnings
     *
     * @return list<TocEntry>
     */
    private function tableOfContents(EpubFile $book, array &$warnings): array
    {
        try {
            return $book->getTableOfContents()->getEntries();
        } catch (Exception) {
            $warnings[] = 'The table of contents could not be read, so the parts have an entry for their first document only.';

            return [];
        }
    }

    /**
     * @param list<string> $warnings
     *
     * @return list<Landmark>
     */
    private function landmarks(EpubFile $book, array &$warnings): array
    {
        try {
            return $book->getTableOfContents()->getLandmarks();
        } catch (Exception) {
            $warnings[] = 'The landmarks could not be read, so the parts have none.';

            return [];
        }
    }

    /**
     * @param list<int> $positions The reading order positions of the part.
     * @param array<int, string> $spinePaths
     * @param list<TocEntry> $toc
     * @param list<Landmark> $landmarks
     * @param list<string> $warnings
     *
     * @throws Exception
     */
    private function buildPart(
        EpubFile $part,
        EpubFile $book,
        SplitPlan $plan,
        array $positions,
        array $spinePaths,
        ReferenceAnalysis $analysis,
        array $toc,
        array $landmarks,
        int $number,
        int $total,
        array &$warnings
    ): void {
        $directory = (string) $part->getTempDir();
        $inPart = array_fill_keys($positions, true);
        $partPaths = array_map(static fn (int $position): string => $spinePaths[$position], $positions);
        $keep = $this->closure([...$partPaths, ...$this->sharedRoots($book)], $spinePaths, $inPart, $analysis);

        // Reading order: only this part's items.
        $spine = $part->getSpine();
        foreach ($book->getSpine()->get() as $position => $idref) {
            if (! isset($inPart[$position]) && $spine->contains($idref)) {
                $spine->remove($idref);
            }
        }

        $this->prune($part, $directory, $keep, $warnings);

        // A hyperlink may only point to a document of the reading order, so every other content document
        // (another part's, and the navigation document when it is not in this part's reading order) is unlinked.
        $inSpine = array_fill_keys($partPaths, true);
        $removedDocuments = [];
        foreach ($spinePaths as $path) {
            if (! isset($inSpine[$path])) {
                $removedDocuments[$path] = true;
            }
        }

        foreach ($part->getManifest()->getItems() as $item) {
            if ($item->path !== '' && $item->mediaType === 'application/xhtml+xml' && ! isset($inSpine[$item->path])) {
                $removedDocuments[$item->path] = true;
            }
        }

        $ncx = array_values(array_filter($part->getManifest()->getItems(), static fn (ManifestItem $item): bool => $item->mediaType === 'application/x-dtbncx+xml'));
        if ($ncx !== [] && $spine->getToc() === null) {
            $spine->setToc($ncx[0]->id);
        }

        $this->pruneNavigations($part, $directory, $inSpine);
        $this->unlinkRemovedDocuments($part, $directory, $removedDocuments);
        $this->prunePageLists($part, $directory, $inSpine);
        $this->setMetadata($part, $plan, $number, $total);

        $navigation = $part->getTableOfContents();
        if ($navigation->isAvailable()) {
            $entries = $this->keptEntries($toc, $inSpine);
            $navigation->setEntries($entries !== [] ? $entries : [new TocEntry($part->getMetadata()->getTitle(), $partPaths[0])]);
        }

        try {
            $navigation->setLandmarks(array_values(array_filter($landmarks, static fn (Landmark $landmark): bool => isset($inSpine[$landmark->path]))));
        } catch (Exception) {
            // A book with neither a navigation document nor a guide has nowhere to keep landmarks.
        }
    }

    /**
     * Deletes the files of the manifest that are not needed, and their encryption entries.
     *
     * @param array<string, true> $keep
     * @param list<string> $warnings
     *
     * @throws Exception
     */
    private function prune(EpubFile $part, string $directory, array $keep, array &$warnings): void
    {
        $manifest = $part->getManifest();
        $obfuscation = new FontObfuscation($directory, $part->getXmlParser(), $this->paths);
        try {
            $fonts = $obfuscation->obfuscatedFonts();
        } catch (Exception) {
            $fonts = [];
            $warning = 'META-INF/encryption.xml could not be read, so the parts keep its entries for fonts they no longer have.';
            in_array($warning, $warnings, true) || $warnings[] = $warning;
        }

        foreach ($manifest->getItems() as $item) {
            if ($item->path === '' || isset($keep[$item->path])) {
                continue;
            }

            $file = $this->paths->resolve($directory, $item->path);
            ! is_file($file) || @unlink($file) || throw new Exception("Failed to delete: {$item->path}");
            $manifest->remove($item->id);
            if (isset($fonts[$item->path])) {
                $obfuscation->setAlgorithm($item->path, null);
            }
        }
    }

    /**
     * @param array<string, true> $removed
     *
     * @throws Exception
     */
    private function unlinkRemovedDocuments(EpubFile $part, string $directory, array $removed): void
    {
        if ($removed === []) {
            return;
        }

        foreach ($part->getManifest()->getItems() as $item) {
            if ($item->path === '' || $item->mediaType !== 'application/xhtml+xml') {
                continue;
            }

            $content = FileSystemHelper::readFile($this->paths->resolve($directory, $item->path));
            $unlinked = $content === null ? null : (new LinkRemover($this->paths, $part->getXmlParser()))->remove($item->path, $content, $removed);
            if ($unlinked !== null) {
                $part->getContentManager()->updateContent($item->path, $unlinked);
            }
        }
    }

    /**
     * Keeps, in the page list of the navigation document and of the NCX, the pages that are in the part's reading
     * order; the others go, and so does a page list left with no page.
     *
     * @param array<string, true> $keep The reading order documents of the part.
     *
     * @throws Exception
     */
    private function prunePageLists(EpubFile $part, string $directory, array $keep): void
    {
        $links = new LinkRemover($this->paths, $part->getXmlParser());
        foreach ($part->getManifest()->getItems() as $item) {
            $isNavigation = in_array('nav', explode(' ', $item->properties), true);
            if ($item->path === '' || (! $isNavigation && $item->mediaType !== 'application/x-dtbncx+xml')) {
                continue;
            }

            $file = $this->paths->resolve($directory, $item->path);
            try {
                $xml = $part->getXmlParser()->parse($file);
            } catch (XmlException) {
                continue;
            }

            $root = dom_import_simplexml($xml);
            $xpath = new DOMXPath($root->ownerDocument ?? new DOMDocument());
            $changed = false;
            if ($isNavigation) {
                $query = "//*[local-name()='nav'][@*[local-name()='type' and contains(concat(' ', normalize-space(.), ' '), ' page-list ')]]";
                foreach ($this->elements($xpath->query($query)) as $nav) {
                    foreach (array_reverse($this->elements($xpath->query(".//*[local-name()='li']", $nav))) as $entry) {
                        $changed = $this->pruneEntry($entry, $xpath, $links, $item->path, $keep) || $changed;
                    }

                    // pruneNavigations() has already pruned (and dropped, when emptied) every nav but the toc and the
                    // landmarks with the same rule, so a page list reaching this point still has its pages.
                    // @codeCoverageIgnoreStart
                    if ($this->countNodes($xpath, ".//*[local-name()='li']", $nav) === 0 && $nav->parentNode instanceof \DOMNode) {
                        $nav->parentNode->removeChild($nav);
                        $changed = true;
                    }

                    // @codeCoverageIgnoreEnd
                }
            } else {
                foreach ($this->elements($xpath->query("//*[local-name()='pageList']")) as $pageList) {
                    foreach ($this->elements($xpath->query("./*[local-name()='pageTarget']", $pageList)) as $target) {
                        $content = $this->elements($xpath->query("./*[local-name()='content']", $target))[0] ?? null;
                        $source = $content instanceof DOMElement ? $content->getAttribute('src') : '';
                        $path = $links->target($source, $item->path);
                        if ($path !== null && ! isset($keep[$path])) {
                            $pageList->removeChild($target);
                            $changed = true;
                        }
                    }

                    if ($this->countNodes($xpath, "./*[local-name()='pageTarget']", $pageList) === 0 && $pageList->parentNode instanceof \DOMNode) {
                        $pageList->parentNode->removeChild($pageList);
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                $part->getXmlParser()->save($xml, $file);
            }
        }
    }

    /**
     * Prunes the navs other than the table of contents, landmarks and page list (lists of figures, tables and the
     * like): an entry that points to a document that is not in the part goes, and so does a list or nav left empty.
     *
     * @param array<string, true> $keep
     *
     * @throws Exception
     */
    private function pruneNavigations(EpubFile $part, string $directory, array $keep): void
    {
        $links = new LinkRemover($this->paths, $part->getXmlParser());
        foreach ($part->getManifest()->getItems() as $item) {
            if ($item->path === '' || ! in_array('nav', explode(' ', $item->properties), true)) {
                continue;
            }

            $file = $this->paths->resolve($directory, $item->path);
            try {
                $xml = $part->getXmlParser()->parse($file);
            } catch (XmlException) {
                continue;
            }

            $root = dom_import_simplexml($xml);
            $xpath = new DOMXPath($root->ownerDocument ?? new DOMDocument());
            $changed = false;
            $navs = $xpath->query("//*[local-name()='nav'][not(@*[local-name()='type' and (contains(concat(' ', normalize-space(.), ' '), ' toc ') or contains(concat(' ', normalize-space(.), ' '), ' landmarks '))])]");
            foreach ($this->elements($navs) as $nav) {
                // Deepest entries first, so a parent is judged after its children.
                foreach (array_reverse($this->elements($xpath->query(".//*[local-name()='li']", $nav))) as $entry) {
                    $changed = $this->pruneEntry($entry, $xpath, $links, $item->path, $keep) || $changed;
                }

                if ($this->countNodes($xpath, ".//*[local-name()='li']", $nav) === 0 && $nav->parentNode instanceof \DOMNode) {
                    $nav->parentNode->removeChild($nav);
                    $changed = true;
                }
            }

            if ($changed) {
                $part->getXmlParser()->save($xml, $file);
            }
        }
    }

    /**
     * @param \DOMNodeList<\DOMNameSpaceNode|\DOMNode>|false $nodes
     *
     * @return list<DOMElement> The elements of a query result, in order.
     */
    private function elements(\DOMNodeList|false $nodes): array
    {
        $elements = [];
        foreach ($nodes ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function countNodes(DOMXPath $xpath, string $expression, DOMElement $context): int
    {
        $nodes = $xpath->query($expression, $context);

        return $nodes === false ? 0 : $nodes->length;
    }

    /**
     * Removes a list item whose own link leaves the part and that has no item left below it; with items left, only
     * its link becomes plain text. Lists left without items go too.
     *
     * @param array<string, true> $keep
     *
     * @return bool Whether something changed.
     */
    private function pruneEntry(DOMElement $entry, DOMXPath $xpath, LinkRemover $links, string $navPath, array $keep): bool
    {
        $changed = false;
        foreach ($this->elements($xpath->query("./*[local-name()='a']", $entry)) as $link) {
            $target = $links->target($link->getAttribute('href'), $navPath);
            if ($target === null || isset($keep[$target])) {
                continue;
            }

            if ($this->countNodes($xpath, ".//*[local-name()='li']", $entry) === 0) {
                $entry->parentNode?->removeChild($entry);

                return true;
            }

            $span = $link->ownerDocument?->createElementNS($link->namespaceURI, 'span');
            if ($span instanceof DOMElement) {
                while ($link->firstChild instanceof \DOMNode) {
                    $span->appendChild($link->firstChild);
                }

                $entry->replaceChild($span, $link);
                $changed = true;
            }
        }

        // A list without items is not valid; its parent item is judged next, as the order is deepest first.
        foreach ($this->elements($xpath->query("./*[local-name()='ol' or local-name()='ul']", $entry)) as $list) {
            if ($this->countNodes($xpath, "./*[local-name()='li']", $list) === 0) {
                $entry->removeChild($list);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @throws Exception
     */
    private function setMetadata(EpubFile $part, SplitPlan $plan, int $number, int $total): void
    {
        $metadata = $part->getMetadata();
        $metadata->setIdentifiers([BookTemplate::uuidUrn()]);
        if ($total > 1) {
            $metadata->setTitle(strtr($plan->titlePattern, [
                '{title}' => $metadata->getTitle(),
                '{n}' => (string) $number,
                '{total}' => (string) $total,
            ]));
        }
    }

    /**
     * The entries that point into the part. An entry whose own file is elsewhere stays, unlinked, when some of its
     * children are here.
     *
     * @param list<TocEntry> $entries
     * @param array<string, true> $keep
     *
     * @return list<TocEntry>
     */
    private function keptEntries(array $entries, array $keep): array
    {
        $kept = [];
        foreach ($entries as $entry) {
            $children = $this->keptEntries($entry->children, $keep);
            if ($entry->path !== '' && isset($keep[$entry->path])) {
                $kept[] = new TocEntry($entry->title, $entry->path, $entry->fragment, $children);
            } elseif ($children !== []) {
                $kept[] = new TocEntry($entry->title, '', null, $children);
            }
        }

        return $kept;
    }
}
