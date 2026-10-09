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
use PhpEpub\InvalidEpubException;
use PhpEpub\Landmark;
use PhpEpub\ManifestItem;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
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
 * children are here. Page lists are dropped. A link in a document to a document of another part becomes plain text
 * (the link element is replaced by its content; a <link> element to it is removed). Documents that are not
 * well-formed are left as they are.
 *
 * The book is only read; nothing on its disk changes (unsaved edits are included).
 */
final readonly class Splitter
{
    private const string XLINK = 'http://www.w3.org/1999/xlink';

    public function __construct(
        private PathResolver $paths = new PathResolver(),
        private XmlParser $xmlParser = new XmlParser()
    ) {
    }

    /**
     * Writes the parts into a directory (created when missing).
     *
     * @return list<string> The paths of the parts, in order.
     *
     * @throws Exception If the book is not loaded or DRM-protected, the plan does not fit the book (a range beyond
     *                   the reading order, no table of contents entries to split at), or a part cannot be written.
     */
    public function split(EpubFile $book, SplitPlan $plan, string $outputDirectory): array
    {
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

        $analysis = ReferenceGraph::forBook($book)->analyzeFrom($this->roots($book, $spinePaths));
        $groups = $this->groups($book, $plan, $spinePaths, $analysis, $directory);
        $total = count($groups);

        is_dir($outputDirectory) || @mkdir($outputDirectory, 0777, true) || is_dir($outputDirectory) || throw new Exception("Failed to create directory: {$outputDirectory}");

        $archive = $book->saveToString();
        $toc = $this->tableOfContents($book);
        $landmarks = $this->landmarks($book);
        $width = max(2, strlen((string) $total));
        $written = [];

        foreach ($groups as $number => $positions) {
            $path = rtrim($outputDirectory, '/\\') . DIRECTORY_SEPARATOR . sprintf('%s-%0' . $width . 'd.epub', $plan->filePrefix, $number + 1);
            $part = EpubFile::openString($archive);

            try {
                $this->buildPart($part, $book, $plan, $positions, $spinePaths, $analysis, $toc, $landmarks, $number + 1, $total);
                ModifiedDate::save($part, $path, $plan->clock);
            } finally {
                $part->cleanup();
            }

            $written[] = $path;
        }

        return $written;
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
     * @param array<int, string> $spinePaths
     *
     * @return list<list<int>>
     *
     * @throws Exception
     */
    private function groups(EpubFile $book, SplitPlan $plan, array $spinePaths, ReferenceAnalysis $analysis, string $directory): array
    {
        $positions = array_keys($spinePaths);

        $groups = match ($plan->kind) {
            SplitKind::Count => array_chunk($positions, max(1, $plan->count)),
            SplitKind::Ranges => $this->rangeGroups($plan, $spinePaths),
            SplitKind::Toc => $this->tocGroups($book, $plan->level, $spinePaths),
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
     * @param array<int, string> $spinePaths
     *
     * @return list<list<int>>
     *
     * @throws Exception
     */
    private function tocGroups(EpubFile $book, int $level, array $spinePaths): array
    {
        $positionOf = [];
        foreach ($spinePaths as $position => $path) {
            $positionOf[$path] ??= $position;
        }

        $starts = [];
        foreach ($this->entriesAtLevel($this->tableOfContents($book), $level) as $entry) {
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
     * @return list<TocEntry>
     */
    private function tableOfContents(EpubFile $book): array
    {
        try {
            return $book->getTableOfContents()->getEntries();
        } catch (Exception) {
            return [];
        }
    }

    /**
     * @return list<Landmark>
     */
    private function landmarks(EpubFile $book): array
    {
        try {
            return $book->getTableOfContents()->getLandmarks();
        } catch (Exception) {
            return [];
        }
    }

    /**
     * @param list<int> $positions The reading order positions of the part.
     * @param array<int, string> $spinePaths
     * @param list<TocEntry> $toc
     * @param list<Landmark> $landmarks
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
        int $total
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

        $this->prune($part, $directory, $keep);

        $removedDocuments = [];
        foreach ($spinePaths as $position => $path) {
            if (! isset($inPart[$position]) && ! isset($keep[$path])) {
                $removedDocuments[$path] = true;
            }
        }

        $ncx = array_values(array_filter($part->getManifest()->getItems(), static fn (ManifestItem $item): bool => $item->mediaType === 'application/x-dtbncx+xml'));
        if ($ncx !== [] && $spine->getToc() === null) {
            $spine->setToc($ncx[0]->id);
        }

        $this->unlinkRemovedDocuments($part, $directory, $removedDocuments);
        $this->dropPageLists($part, $directory);
        $this->setMetadata($part, $plan, $number, $total);

        $navigation = $part->getTableOfContents();
        if ($navigation->isAvailable()) {
            $entries = $this->keptEntries($toc, $keep);
            $navigation->setEntries($entries !== [] ? $entries : [new TocEntry($part->getMetadata()->getTitle(), $partPaths[0])]);
        }

        try {
            $navigation->setLandmarks(array_values(array_filter($landmarks, static fn (Landmark $landmark): bool => isset($keep[$landmark->path]))));
        } catch (Exception) {
            // A book with neither a navigation document nor a guide has nowhere to keep landmarks.
        }
    }

    /**
     * Deletes the files of the manifest that are not needed, and their encryption entries.
     *
     * @param array<string, true> $keep
     *
     * @throws Exception
     */
    private function prune(EpubFile $part, string $directory, array $keep): void
    {
        $manifest = $part->getManifest();
        $obfuscation = new FontObfuscation($directory, $this->xmlParser, $this->paths);
        try {
            $fonts = $obfuscation->obfuscatedFonts();
        } catch (Exception) {
            $fonts = [];
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
            $isNavigation = in_array('nav', explode(' ', $item->properties), true);
            if ($item->path === '' || $isNavigation || $item->mediaType !== 'application/xhtml+xml') {
                continue;
            }

            $content = FileSystemHelper::readFile($this->paths->resolve($directory, $item->path));
            $unlinked = $content === null ? null : $this->unlink($item->path, $content, $removed);
            if ($unlinked !== null) {
                $part->getContentManager()->updateContent($item->path, $unlinked);
            }
        }
    }

    /**
     * The document without its links to the removed documents, or null when it has none (or is not well-formed).
     *
     * @param array<string, true> $removed
     */
    private function unlink(string $path, string $content, array $removed): ?string
    {
        $names = array_map(basename(...), array_keys($removed));
        $mentioned = false;
        foreach ($names as $name) {
            if (str_contains($content, $name) || str_contains($content, rawurlencode($name))) {
                $mentioned = true;
                break;
            }
        }

        if (! $mentioned) {
            return null;
        }

        try {
            $root = dom_import_simplexml($this->xmlParser->parseString($content, $path));
        } catch (XmlException) {
            return null;
        }

        $document = $root->ownerDocument ?? new DOMDocument();
        $changed = false;
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            $name = strtolower($element->localName ?? '');
            if (! in_array($name, ['a', 'area', 'link'], true)) {
                continue;
            }

            $href = $element->getAttribute('href');
            $href = $href !== '' ? $href : $element->getAttributeNS(self::XLINK, 'href');
            $target = $this->target($href, $path);
            if ($target === null || ! isset($removed[$target]) || ! $element->parentNode instanceof \DOMNode) {
                continue;
            }

            if ($name === 'a') {
                while ($element->firstChild instanceof \DOMNode) {
                    $element->parentNode->insertBefore($element->firstChild, $element);
                }
            }

            $element->parentNode->removeChild($element);
            $changed = true;
        }

        return $changed ? ($document->saveXML() ?: null) : null;
    }

    private function target(string $reference, string $fromPath): ?string
    {
        $reference = trim($reference);
        if ($reference === '' || str_starts_with($reference, '#') || str_starts_with($reference, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) === 1) {
            return null;
        }

        $file = rawurldecode(substr($reference, 0, strcspn($reference, '?#')));
        if ($file === '') {
            return null;
        }

        $directory = dirname($fromPath);

        try {
            return $this->paths->normalize(($directory === '.' ? '' : $directory . '/') . $file);
        } catch (InvalidEpubException) {
            return null;
        }
    }

    /**
     * Removes the page list from the navigation document and the NCX: its pages are in the whole book.
     *
     * @throws Exception
     */
    private function dropPageLists(EpubFile $part, string $directory): void
    {
        foreach ($part->getManifest()->getItems() as $item) {
            $isNavigation = in_array('nav', explode(' ', $item->properties), true);
            if ($item->path === '' || (! $isNavigation && $item->mediaType !== 'application/x-dtbncx+xml')) {
                continue;
            }

            $file = $this->paths->resolve($directory, $item->path);
            try {
                $xml = $this->xmlParser->parse($file);
            } catch (XmlException) {
                continue;
            }

            $root = dom_import_simplexml($xml);
            $xpath = new DOMXPath($root->ownerDocument ?? new DOMDocument());
            $query = $isNavigation
                ? "//*[local-name()='nav'][@*[local-name()='type' and contains(concat(' ', normalize-space(.), ' '), ' page-list ')]]"
                : "//*[local-name()='pageList']";

            $changed = false;
            foreach (iterator_to_array($xpath->query($query) ?: []) as $node) {
                if ($node instanceof DOMElement) {
                    $node->parentNode?->removeChild($node);
                    $changed = true;
                }
            }

            if ($changed) {
                $this->xmlParser->save($xml, $file);
            }
        }
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
