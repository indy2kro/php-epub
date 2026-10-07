<?php

declare(strict_types=1);

namespace PhpEpub;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpEpub\Util\PathResolver;
use PhpEpub\Util\XmlText;

/**
 * Reads and edits the table of contents: the EPUB 3 navigation document (its "toc" nav)
 * and the EPUB 2 NCX. Changes are written to those files straight away.
 */
final readonly class TableOfContents
{
    private const string NCX_MEDIA_TYPE = 'application/x-dtbncx+xml';

    private const string OPS_NAMESPACE = 'http://www.idpf.org/2007/ops';

    private const string TOC_NAV = "//x:nav[contains(concat(' ', normalize-space(@epub:type), ' '), ' toc ')]";

    private const string LANDMARKS_NAV = "//x:nav[contains(concat(' ', normalize-space(@epub:type), ' '), ' landmarks ')]";

    private const string PAGE_LIST_NAV = "//x:nav[contains(concat(' ', normalize-space(@epub:type), ' '), ' page-list ')]";

    /**
     * @param string $rootDirectory The directory holding the extracted book.
     * @param EpubFile|null $book The book these files belong to: holding it keeps its extracted
     *                            files alive while this object is used (e.g. EpubFile::open($path)->getTableOfContents()).
     * @param Spine|null $spine The reading order generateFromHeadings() follows.
     */
    public function __construct(
        private string $rootDirectory,
        private Manifest $manifest,
        private XmlParser $xmlParser = new XmlParser(),
        private PathResolver $paths = new PathResolver(),
        private ?EpubFile $book = null,
        private ?Spine $spine = null
    ) {
    }

    /**
     * The book this table of contents belongs to, when it was created by EpubFile.
     */
    public function getBook(): ?EpubFile
    {
        return $this->book;
    }

    /**
     * Whether the book has a navigation document or NCX that can hold a table of contents.
     */
    public function isAvailable(): bool
    {
        return $this->navPath() !== null || $this->ncxPath() !== null;
    }

    /**
     * The entries of the navigation document's toc, or else of the NCX; [] when the book has neither.
     *
     * @return list<TocEntry>
     *
     * @throws Exception If the navigation document or NCX cannot be parsed.
     */
    public function getEntries(): array
    {
        $navPath = $this->navPath();
        if ($navPath !== null) {
            $list = $this->first($this->navXPath($this->load($navPath)), self::TOC_NAV . '/x:ol');

            return $list instanceof DOMElement ? $this->readNavList($list, $navPath) : [];
        }

        $ncxPath = $this->ncxPath();
        if ($ncxPath === null) {
            return [];
        }

        $navMap = $this->childElements($this->load($ncxPath), 'navMap')[0] ?? null;

        return $navMap instanceof DOMElement ? $this->readNavPoints($navMap, $ncxPath) : [];
    }

    /**
     * Replaces the entries in the navigation document and in the NCX (each that the book has).
     * Other navs (landmarks, page list) and the toc heading are kept.
     *
     * In the NCX, which has no unlinked entries, an entry without a path is left out and its
     * children take its place.
     *
     * @param list<TocEntry> $entries
     *
     * @throws Exception If $entries is empty (a table of contents needs an entry), the book has no
     *                   navigation document or NCX, an entry points outside the book or is not valid
     *                   XML text, or a file cannot be written.
     */
    public function setEntries(array $entries): void
    {
        if ($entries === []) {
            throw new Exception('A table of contents needs at least one entry');
        }

        $this->writeEntries($entries);
    }

    /**
     * Like setEntries(), but accepts an empty list. ContentManager uses it to keep an emptied table
     * of contents (after the last linked file was deleted) in its files; EpubFile::validate()
     * reports it (NAV_EMPTY / NCX_EMPTY).
     *
     * @internal
     *
     * @param list<TocEntry> $entries
     *
     * @throws Exception See setEntries().
     */
    public function writeEntries(array $entries): void
    {
        $navPath = $this->navPath();
        $ncxPath = $this->ncxPath();
        if ($navPath === null && $ncxPath === null) {
            throw new Exception('The book has no navigation document or NCX to hold a table of contents');
        }

        $this->assertValid($entries);

        if ($navPath !== null) {
            $this->writeNav($navPath, $entries);
        }

        if ($ncxPath !== null) {
            $this->writeNcx($ncxPath, $entries);
        }
    }

    /**
     * The landmarks (cover, table of contents, start of the body, …): those of the navigation
     * document's "landmarks" nav or, when it has none, the EPUB 2 <guide> references that have an
     * EPUB 3 equivalent (their types are translated, e.g. the guide's "text" is "bodymatter").
     * Landmarks that point outside the book are left out.
     *
     * @return list<Landmark>
     *
     * @throws Exception If the navigation document cannot be parsed.
     */
    public function getLandmarks(): array
    {
        $navPath = $this->navPath();
        $landmarks = $navPath === null ? null : $this->readNavLandmarks($navPath);
        if ($landmarks !== null) {
            return $landmarks;
        }

        $landmarks = [];
        foreach ($this->manifest->getGuideReferences() as $reference) {
            $type = Landmark::fromGuideType($reference->type);
            if ($type !== null) {
                $landmarks[] = new Landmark($type, $reference->title, $reference->path, $reference->fragment);
            }
        }

        return $landmarks;
    }

    /**
     * Replaces the landmarks. They are written to the navigation document's "landmarks" nav (created
     * when missing) and, in an EPUB 2 book or an EPUB 3 book that keeps a <guide>, to the guide
     * (landmarks whose type the guide cannot express, such as "chapter", are left out of it: see
     * Landmark::toGuideType()). [] removes the landmarks nav and the guide.
     *
     * @param list<Landmark> $landmarks
     *
     * @throws Exception If a landmark has an empty type, title or path, a value is not valid XML text, a path
     *                   leaves the book, the book has neither a navigation document nor a guide to hold
     *                   landmarks, or a file cannot be written.
     */
    public function setLandmarks(array $landmarks): void
    {
        foreach ($landmarks as $landmark) {
            if (trim($landmark->type) === '' || trim($landmark->title) === '' || $landmark->path === '') {
                throw new Exception('A landmark needs a type, a title and a path');
            }

            XmlText::assertValid($landmark->type, $landmark->title, $landmark->fragment ?? '');
            $this->paths->normalize($landmark->path);
        }

        $navPath = $this->navPath();
        $keepsGuide = ! $this->manifest->isEpub3() || $this->manifest->getGuideReferences() !== [];
        if ($navPath === null && ! $keepsGuide) {
            throw new Exception('The book has no navigation document or guide to hold landmarks');
        }

        if ($navPath !== null) {
            $this->writeNavLandmarks($navPath, $landmarks);
        }

        if ($keepsGuide) {
            $references = [];
            foreach ($landmarks as $landmark) {
                $guideType = Landmark::toGuideType($landmark->type);
                if ($guideType !== null) {
                    $references[] = new Landmark($guideType, $landmark->title, $landmark->path, $landmark->fragment);
                }
            }

            $this->manifest->setGuideReferences($references);
        }
    }

    /**
     * The page list: the print page numbers of the navigation document's "page-list" nav or, when it
     * has none, of the NCX pageList; [] when the book has neither.
     *
     * @return list<TocEntry>
     *
     * @throws Exception If the navigation document or NCX cannot be parsed.
     */
    public function getPageList(): array
    {
        $navPath = $this->navPath();
        if ($navPath !== null) {
            $list = $this->first($this->navXPath($this->load($navPath)), self::PAGE_LIST_NAV . '/x:ol');
            if ($list instanceof DOMElement) {
                return $this->readNavList($list, $navPath);
            }
        }

        $ncxPath = $this->ncxPath();
        $pageList = $ncxPath === null ? null : ($this->childElements($this->load($ncxPath), 'pageList')[0] ?? null);
        if ($ncxPath === null || ! $pageList instanceof DOMElement) {
            return [];
        }

        $entries = [];
        foreach ($this->childElements($pageList, 'pageTarget') as $target) {
            $title = '';
            $path = '';
            $fragment = null;
            foreach ($this->childElements($target) as $child) {
                if ($child->localName === 'navLabel') {
                    $title = $this->collapse($child->textContent);
                } elseif ($child->localName === 'content' && $child->hasAttribute('src')) {
                    [$path, $fragment] = $this->resolveHref($ncxPath, $child->getAttribute('src'));
                }
            }

            $entries[] = new TocEntry($title, $path, $fragment);
        }

        return $entries;
    }

    /**
     * Appends a top-level entry.
     *
     * @throws Exception See setEntries().
     */
    public function addEntry(TocEntry $entry): void
    {
        $this->setEntries([...$this->getEntries(), $entry]);
    }

    /**
     * Builds the table of contents from the headings (h1 to h$maxLevel) of the spine documents, in
     * reading order, and sets it as the book's entries. Headings nest by level (an h2 after an h1 is
     * its child). A heading without an id gets one ("toc-N", unique in its document), which rewrites
     * that document. Documents that are not well-formed XML and non-linear spine items are skipped,
     * as are the navigation document and headings without text.
     *
     * @param int $maxLevel The deepest heading level to include, 1 to 6.
     *
     * @return list<TocEntry> The new entries.
     *
     * @throws Exception If $maxLevel is out of range, the table of contents has no spine (it did not
     *                   come from EpubFile), the book has no navigation document or NCX, no heading was
     *                   found (a table of contents needs an entry), or a file cannot be written.
     */
    public function generateFromHeadings(int $maxLevel = 3): array
    {
        if ($maxLevel < 1 || $maxLevel > 6) {
            throw new Exception("The deepest heading level must be 1 to 6, got {$maxLevel}");
        }

        if (! $this->spine instanceof Spine) {
            throw new Exception('The spine is needed to read the headings: use EpubFile::getTableOfContents()');
        }

        if (! $this->isAvailable()) {
            throw new Exception('The book has no navigation document or NCX to hold a table of contents');
        }

        $headings = [];
        foreach ($this->spine->getItems() as $spineItem) {
            $item = $spineItem->item;
            $isNav = $item instanceof ManifestItem && in_array('nav', explode(' ', $item->properties), true);
            if (! $spineItem->linear || ! $item instanceof ManifestItem || $isNav || $item->mediaType !== 'application/xhtml+xml' || $item->path === '') {
                continue;
            }

            foreach ($this->headings($item->path, $maxLevel) as [$level, $title, $id]) {
                $headings[] = [$level, $title, $item->path, $id];
            }
        }

        $index = 0;
        $entries = $this->nestHeadings($headings, $index, 0);
        $this->setEntries($entries);

        return $entries;
    }

    /**
     * The headings of one document as [level, title, id], giving those without an id one (the
     * document is then rewritten). [] for a document that cannot be read as XML.
     *
     * @return list<array{int, string, string}>
     */
    private function headings(string $path, int $maxLevel): array
    {
        try {
            $root = $this->load($path);
        } catch (XmlException) {
            return [];
        }

        $ids = [];
        foreach ($root->getElementsByTagName('*') as $element) {
            $ids[$element->getAttribute('id')] = true;
        }

        $headings = [];
        $added = 0;
        $changed = false;
        foreach ($root->getElementsByTagName('*') as $element) {
            $level = preg_match('/^h([1-6])$/i', $element->localName ?? '', $match) === 1 ? (int) $match[1] : 0;
            $title = $this->collapse($element->textContent);
            if ($level === 0 || $level > $maxLevel || $title === '') {
                continue;
            }

            if ($element->getAttribute('id') === '') {
                do {
                    $id = 'toc-' . ++$added;
                } while (isset($ids[$id]));

                $element->setAttribute('id', $id);
                $ids[$id] = true;
                $changed = true;
            }

            $headings[] = [$level, $title, $element->getAttribute('id')];
        }

        if ($changed) {
            $this->save($root, $path);
        }

        return $headings;
    }

    /**
     * Nests headings (in reading order) by level: a heading's children are the deeper headings after it.
     *
     * @param list<array{int, string, string, string}> $headings [level, title, path, id]
     *
     * @return list<TocEntry>
     */
    private function nestHeadings(array $headings, int &$index, int $parentLevel): array
    {
        $entries = [];
        while (isset($headings[$index]) && $headings[$index][0] > $parentLevel) {
            [$level, $title, $path, $id] = $headings[$index++];
            $entries[] = new TocEntry($title, $path, $id, $this->nestHeadings($headings, $index, $level));
        }

        return $entries;
    }

    /**
     * Keeps the NCX in step with the package: its dtb:uid meta must equal the unique identifier
     * (EPUBCheck reports a mismatch), and its docTitle, which EPUB 2 reading systems show, is the
     * book title. Missing elements are created; an empty value is left alone. EpubFile::save() calls
     * this after metadata changes. Nothing is written without an NCX, when it already matches, or
     * when it cannot be parsed (validate() reports that).
     *
     * @internal
     *
     * @throws Exception If the NCX cannot be written.
     */
    public function syncNcx(string $title, ?string $uniqueIdentifier): void
    {
        $ncxPath = $this->ncxPath();
        if ($ncxPath === null) {
            return;
        }

        try {
            $root = $this->load($ncxPath);
        } catch (Exception) {
            return;
        }

        $document = $this->document($root);
        $namespace = (string) $root->namespaceURI;
        $changed = false;

        // head comes first, docTitle follows it.
        $head = $this->childElements($root, 'head')[0] ?? null;
        if ($uniqueIdentifier !== null && $uniqueIdentifier !== '') {
            $head ??= $root->insertBefore($document->createElementNS($namespace, 'head'), $root->firstChild);
            $uid = array_values(array_filter(
                $this->childElements($head, 'meta'),
                static fn (DOMElement $meta): bool => $meta->getAttribute('name') === 'dtb:uid'
            ))[0] ?? null;
            if (! $uid instanceof DOMElement) {
                $uid = $head->insertBefore($document->createElementNS($namespace, 'meta'), $head->firstChild);
                $uid->setAttribute('name', 'dtb:uid');
            }

            if ($uid->getAttribute('content') !== $uniqueIdentifier) {
                $uid->setAttribute('content', $uniqueIdentifier);
                $changed = true;
            }
        }

        if ($title !== '') {
            $docTitle = $this->childElements($root, 'docTitle')[0]
                ?? $root->insertBefore($document->createElementNS($namespace, 'docTitle'), $head->nextSibling ?? $root->firstChild);
            $text = $this->childElements($docTitle, 'text')[0] ?? $docTitle->appendChild($document->createElementNS($namespace, 'text'));
            if ($text->textContent !== $title) {
                $text->textContent = $title;
                $changed = true;
            }
        }

        if ($changed) {
            $this->updateDepth($root);
            $this->save($root, $ncxPath);
        }
    }

    private function navPath(): ?string
    {
        foreach ($this->manifest->getItems() as $item) {
            if ($item->path !== '' && in_array('nav', explode(' ', $item->properties), true)) {
                return $item->path;
            }
        }

        return null;
    }

    private function ncxPath(): ?string
    {
        foreach ($this->manifest->getItems() as $item) {
            if ($item->path !== '' && $item->mediaType === self::NCX_MEDIA_TYPE) {
                return $item->path;
            }
        }

        return null;
    }

    /**
     * Parses a document (safely, with XmlParser) and returns its root element.
     *
     * @throws Exception
     */
    private function load(string $path): DOMElement
    {
        $root = dom_import_simplexml($this->xmlParser->parse($this->paths->resolve($this->rootDirectory, $path)));

        return $root instanceof DOMElement ? $root : throw new Exception("Failed to load: {$path}");
    }

    /**
     * @throws Exception
     */
    private function save(DOMElement $root, string $path): void
    {
        if (@$root->ownerDocument?->save($this->paths->resolve($this->rootDirectory, $path)) === false) {
            throw new Exception("Failed to write the table of contents to: {$path}");
        }
    }

    /**
     * XPath for a navigation document: "x" is its XHTML namespace, "epub" the OPS namespace.
     */
    private function navXPath(DOMElement $root): DOMXPath
    {
        $xpath = new DOMXPath($this->document($root));
        $xpath->registerNamespace('x', (string) $root->namespaceURI);
        $xpath->registerNamespace('epub', self::OPS_NAMESPACE);

        return $xpath;
    }

    private function first(DOMXPath $xpath, string $expression, ?DOMElement $context = null): ?DOMElement
    {
        $nodes = $xpath->query($expression, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * @return list<TocEntry>
     */
    private function readNavList(DOMElement $list, string $navPath): array
    {
        $entries = [];
        foreach ($this->childElements($list, 'li') as $item) {
            $label = null;
            $children = [];
            foreach ($this->childElements($item) as $child) {
                if ($label === null && in_array($child->localName, ['a', 'span'], true)) {
                    $label = $child;
                } elseif ($child->localName === 'ol') {
                    $children = $this->readNavList($child, $navPath);
                }
            }

            if (! $label instanceof DOMElement) {
                continue;
            }

            [$path, $fragment] = $label->localName === 'a' && $label->hasAttribute('href')
                ? $this->resolveHref($navPath, $label->getAttribute('href'))
                : ['', null];
            $entries[] = new TocEntry($this->collapse($label->textContent), $path, $fragment, $children);
        }

        return $entries;
    }

    /**
     * Replaces the toc nav's list, creating the toc nav when the document has none.
     *
     * @param list<TocEntry> $entries
     *
     * @throws Exception
     */
    private function writeNav(string $navPath, array $entries): void
    {
        $root = $this->load($navPath);
        $document = $this->document($root);
        $namespace = (string) $root->namespaceURI;
        $xpath = $this->navXPath($root);

        $nav = $this->first($xpath, self::TOC_NAV);
        if (! $nav instanceof DOMElement) {
            $nav = $document->createElementNS($namespace, 'nav');
            $nav->setAttributeNS(self::OPS_NAMESPACE, 'epub:type', 'toc');
            ($this->first($xpath, '//x:body') ?? $root)->appendChild($nav);
        }

        $newList = $this->buildNavList($document, $namespace, $entries, $navPath);
        $oldList = $this->first($xpath, 'x:ol', $nav);
        if ($oldList instanceof DOMElement) {
            $nav->replaceChild($newList, $oldList);
        } else {
            $nav->appendChild($newList);
        }

        $this->save($root, $navPath);
    }

    /**
     * The landmarks of the navigation document's landmarks nav; null when it has none.
     *
     * @return list<Landmark>|null
     */
    private function readNavLandmarks(string $navPath): ?array
    {
        $xpath = $this->navXPath($this->load($navPath));
        $nav = $this->first($xpath, self::LANDMARKS_NAV);
        if (! $nav instanceof DOMElement) {
            return null;
        }

        $landmarks = [];
        foreach ($xpath->query('.//x:a[@epub:type][@href]', $nav) ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            [$path, $fragment] = $this->resolveHref($navPath, $link->getAttribute('href'));
            $type = trim($link->getAttributeNS(self::OPS_NAMESPACE, 'type'));
            if ($path !== '' && $type !== '') {
                $landmarks[] = new Landmark($type, $this->collapse($link->textContent), $path, $fragment);
            }
        }

        return $landmarks;
    }

    /**
     * Replaces the landmarks nav's list, creating the nav when missing (after the other navs);
     * no landmarks remove it.
     *
     * @param list<Landmark> $landmarks
     *
     * @throws Exception
     */
    private function writeNavLandmarks(string $navPath, array $landmarks): void
    {
        $root = $this->load($navPath);
        $document = $this->document($root);
        $namespace = (string) $root->namespaceURI;
        $xpath = $this->navXPath($root);
        $nav = $this->first($xpath, self::LANDMARKS_NAV);

        if ($landmarks === []) {
            $nav?->parentNode?->removeChild($nav);
            $this->save($root, $navPath);

            return;
        }

        if (! $nav instanceof DOMElement) {
            $nav = $document->createElementNS($namespace, 'nav');
            $nav->setAttributeNS(self::OPS_NAMESPACE, 'epub:type', 'landmarks');
            $nav->setAttribute('hidden', '');
            $nav->appendChild($document->createElementNS($namespace, 'h2'))->appendChild($document->createTextNode('Landmarks'));
            ($this->first($xpath, '//x:body') ?? $root)->appendChild($nav);
        }

        // The list joins the document first, so its links reuse the epub namespace declaration.
        $list = $document->createElementNS($namespace, 'ol');
        $oldList = $this->first($xpath, 'x:ol', $nav);
        $oldList instanceof DOMElement ? $nav->replaceChild($list, $oldList) : $nav->appendChild($list);

        foreach ($landmarks as $landmark) {
            $link = $list->appendChild($document->createElementNS($namespace, 'li'))->appendChild($document->createElementNS($namespace, 'a'));
            $link->setAttributeNS(self::OPS_NAMESPACE, 'epub:type', $landmark->type);
            $link->setAttribute('href', $this->href($navPath, new TocEntry($landmark->title, $landmark->path, $landmark->fragment)));
            $link->appendChild($document->createTextNode($landmark->title));
        }

        $this->save($root, $navPath);
    }

    /**
     * @param list<TocEntry> $entries
     */
    private function buildNavList(DOMDocument $document, string $namespace, array $entries, string $navPath): DOMElement
    {
        $list = $document->createElementNS($namespace, 'ol');
        foreach ($entries as $entry) {
            $item = $list->appendChild($document->createElementNS($namespace, 'li'));

            if ($entry->path === '') {
                $label = $document->createElementNS($namespace, 'span');
            } else {
                $label = $document->createElementNS($namespace, 'a');
                $label->setAttribute('href', $this->href($navPath, $entry));
            }

            $label->appendChild($document->createTextNode($entry->title));
            $item->appendChild($label);

            if ($entry->children !== []) {
                $item->appendChild($this->buildNavList($document, $namespace, $entry->children, $navPath));
            }
        }

        return $list;
    }

    /**
     * @return list<TocEntry>
     */
    private function readNavPoints(DOMElement $parent, string $ncxPath): array
    {
        $entries = [];
        foreach ($this->childElements($parent, 'navPoint') as $point) {
            $title = '';
            $path = '';
            $fragment = null;
            foreach ($this->childElements($point) as $child) {
                if ($child->localName === 'navLabel') {
                    $title = $this->collapse($child->textContent);
                } elseif ($child->localName === 'content' && $child->hasAttribute('src')) {
                    [$path, $fragment] = $this->resolveHref($ncxPath, $child->getAttribute('src'));
                }
            }

            $entries[] = new TocEntry($title, $path, $fragment, $this->readNavPoints($point, $ncxPath));
        }

        return $entries;
    }

    /**
     * Replaces the navPoints of the NCX navMap, creating the navMap when it is missing.
     *
     * @param list<TocEntry> $entries
     *
     * @throws Exception
     */
    private function writeNcx(string $ncxPath, array $entries): void
    {
        $root = $this->load($ncxPath);
        $document = $this->document($root);
        $namespace = (string) $root->namespaceURI;

        $navMap = $this->childElements($root, 'navMap')[0] ?? $root->appendChild($document->createElementNS($namespace, 'navMap'));
        foreach ($this->childElements($navMap, 'navPoint') as $point) {
            $navMap->removeChild($point);
        }

        $playOrder = 0;
        $this->appendNavPoints($document, $namespace, $navMap, $entries, $ncxPath, $playOrder);

        $this->updateDepth($root);
        $this->save($root, $ncxPath);
    }

    /**
     * Sets the NCX's dtb:depth meta (creating it) to the real nesting depth of the navPoints, 0 for
     * none. An NCX without a head is left alone.
     */
    private function updateDepth(DOMElement $root): void
    {
        $head = $this->childElements($root, 'head')[0] ?? null;
        if (! $head instanceof DOMElement) {
            return;
        }

        $depth = $this->navPointDepth($this->childElements($root, 'navMap')[0] ?? $root);
        $meta = array_values(array_filter(
            $this->childElements($head, 'meta'),
            static fn (DOMElement $meta): bool => $meta->getAttribute('name') === 'dtb:depth'
        ))[0] ?? null;
        if (! $meta instanceof DOMElement) {
            $meta = $this->document($root)->createElementNS((string) $root->namespaceURI, 'meta');
            $meta->setAttribute('name', 'dtb:depth');
            $head->appendChild($meta);
        }

        $meta->setAttribute('content', (string) $depth);
    }

    private function navPointDepth(DOMElement $parent): int
    {
        $deepest = 0;
        foreach ($this->childElements($parent, 'navPoint') as $point) {
            $deepest = max($deepest, 1 + $this->navPointDepth($point));
        }

        return $deepest;
    }

    /**
     * @param list<TocEntry> $entries
     */
    private function appendNavPoints(DOMDocument $document, string $namespace, DOMElement $parent, array $entries, string $ncxPath, int &$playOrder): void
    {
        foreach ($entries as $entry) {
            if ($entry->path === '') {
                // The NCX has no unlinked entries: the children take this entry's place.
                $this->appendNavPoints($document, $namespace, $parent, $entry->children, $ncxPath, $playOrder);
                continue;
            }

            $playOrder++;
            $point = $document->createElementNS($namespace, 'navPoint');
            $point->setAttribute('id', 'navPoint-' . $playOrder);
            $point->setAttribute('playOrder', (string) $playOrder);

            $label = $point->appendChild($document->createElementNS($namespace, 'navLabel'));
            $label->appendChild($document->createElementNS($namespace, 'text'))->appendChild($document->createTextNode($entry->title));

            $content = $point->appendChild($document->createElementNS($namespace, 'content'));
            $content->setAttribute('src', $this->href($ncxPath, $entry));

            $parent->appendChild($point);
            $this->appendNavPoints($document, $namespace, $point, $entry->children, $ncxPath, $playOrder);
        }
    }

    /**
     * Resolves an href found in $documentPath to [path relative to the book root, fragment];
     * ['', null] for remote URLs and hrefs that leave the book.
     *
     * @return array{string, string|null}
     */
    private function resolveHref(string $documentPath, string $href): array
    {
        $href = trim($href);
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1) {
            return ['', null];
        }

        $parts = explode('#', $href, 2);
        $file = $parts[0];
        $fragment = ($parts[1] ?? '') === '' ? null : rawurldecode($parts[1]);

        if ($file === '') {
            return [$documentPath, $fragment];
        }

        $directory = dirname($documentPath);

        try {
            return [$this->paths->normalize(($directory === '.' ? '' : $directory . '/') . rawurldecode($file)), $fragment];
        } catch (InvalidEpubException) {
            return ['', null];
        }
    }

    /**
     * @throws InvalidEpubException If the entry's path leaves the book.
     */
    private function href(string $documentPath, TocEntry $entry): string
    {
        $directory = dirname($documentPath);
        $href = $this->paths->relativeHref($directory === '.' ? '' : $directory, $entry->path);

        return $entry->fragment === null || $entry->fragment === '' ? $href : $href . '#' . rawurlencode($entry->fragment);
    }

    /**
     * @param list<TocEntry> $entries
     *
     * @throws Exception
     */
    private function assertValid(array $entries): void
    {
        foreach ($entries as $entry) {
            XmlText::assertValid($entry->title, $entry->fragment ?? '');
            if ($entry->path !== '') {
                $this->paths->normalize($entry->path);
            }

            $this->assertValid($entry->children);
        }
    }

    private function document(DOMElement $root): DOMDocument
    {
        // A parsed element always belongs to a document.
        return $root->ownerDocument ?? new DOMDocument();
    }

    /**
     * @return list<DOMElement>
     */
    private function childElements(DOMElement $parent, ?string $localName = null): array
    {
        $elements = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && ($localName === null || $child->localName === $localName)) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
