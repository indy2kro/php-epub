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

    /**
     * @param string $rootDirectory The directory holding the extracted book.
     * @param EpubFile|null $book The book these files belong to: holding it keeps its extracted
     *                            files alive while this object is used (e.g. EpubFile::open($path)->getTableOfContents()).
     */
    public function __construct(
        private string $rootDirectory,
        private Manifest $manifest,
        private XmlParser $xmlParser = new XmlParser(),
        private PathResolver $paths = new PathResolver(),
        private ?EpubFile $book = null
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
     * @throws Exception If the book has no navigation document or NCX, an entry points outside
     *                   the book or is not valid XML text, or a file cannot be written.
     */
    public function setEntries(array $entries): void
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
     * Appends a top-level entry.
     *
     * @throws Exception See setEntries().
     */
    public function addEntry(TocEntry $entry): void
    {
        $this->setEntries([...$this->getEntries(), $entry]);
    }

    /**
     * Writes the book title into the NCX docTitle, which EPUB 2 reading systems show, creating it
     * when missing. EpubFile::save() calls this after metadata changes. Nothing is written without
     * an NCX, when its docTitle already matches, or when it cannot be parsed (validate() reports that).
     *
     * @internal
     *
     * @throws Exception If the NCX cannot be written.
     */
    public function syncNcxTitle(string $title): void
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

        $docTitle = $this->childElements($root, 'docTitle')[0] ?? null;
        if (! $docTitle instanceof DOMElement) {
            // docTitle follows head.
            $head = $this->childElements($root, 'head')[0] ?? null;
            $docTitle = $root->insertBefore($document->createElementNS($namespace, 'docTitle'), $head->nextSibling ?? $root->firstChild);
        }

        $text = $this->childElements($docTitle, 'text')[0] ?? $docTitle->appendChild($document->createElementNS($namespace, 'text'));
        if ($text->textContent === $title) {
            return;
        }

        $text->textContent = $title;
        $this->save($root, $ncxPath);
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

        $this->save($root, $ncxPath);
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
