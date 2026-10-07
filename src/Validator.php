<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\PathResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;

/**
 * Checks a loaded book for common structural problems: required metadata, manifest and
 * spine consistency, and navigation. It is a quick check before publishing, not a
 * replacement for EPUBCheck.
 */
final readonly class Validator
{
    /**
     * Media types that may appear in the spine without a fallback (EPUB 3 and EPUB 2 content documents).
     */
    private const string MIMETYPE = 'application/epub+zip';

    private const string NCX_MEDIA_TYPE = 'application/x-dtbncx+xml';

    private const string NCX_NAMESPACE = 'http://www.daisy.org/z3986/2005/ncx/';

    private const array CONTENT_MEDIA_TYPES = [
        'application/xhtml+xml',
        'image/svg+xml',
        'application/x-dtbook+xml',
        'text/x-oeb1-document',
    ];

    /**
     * @param string $rootDirectory The directory holding the extracted book.
     */
    public function __construct(
        private string $rootDirectory,
        private SimpleXMLElement $opfXml,
        private Metadata $metadata,
        private Manifest $manifest,
        private Spine $spine,
        private TableOfContents $tableOfContents,
        private PathResolver $paths = new PathResolver(),
        private XmlParser $xmlParser = new XmlParser()
    ) {
    }

    /**
     * @return list<ValidationIssue> Errors and warnings, grouped by area (container, metadata, ids, manifest, spine,
     *                               content documents, navigation).
     */
    public function validate(): array
    {
        return [
            ...$this->checkMimetype(),
            ...$this->checkMetadata(),
            ...$this->checkIds(),
            ...$this->checkManifest(),
            ...$this->checkSpine(),
            ...$this->checkContent(),
            ...$this->checkNavigation(),
        ];
    }

    /**
     * The mimetype file must hold exactly "application/epub+zip"; reading systems tolerate other
     * values, and EpubFile::save() writes the right one.
     *
     * @return list<ValidationIssue>
     */
    private function checkMimetype(): array
    {
        $mimetype = FileSystemHelper::readFile($this->rootDirectory . DIRECTORY_SEPARATOR . 'mimetype');

        return $mimetype === self::MIMETYPE ? [] : [$this->warning(
            'MIMETYPE_INVALID',
            'The mimetype file is missing or does not contain exactly "' . self::MIMETYPE . '"; save() writes the right one.',
            'mimetype'
        )];
    }

    /**
     * @return list<ValidationIssue>
     */
    private function checkMetadata(): array
    {
        $issues = [];
        if (! $this->hasValue($this->metadata->getTitles())) {
            $issues[] = $this->error('METADATA_TITLE_MISSING', 'The package has no dc:title.');
        }

        if (trim($this->metadata->getLanguage()) === '') {
            $issues[] = $this->error('METADATA_LANGUAGE_MISSING', 'The package has no dc:language.');
        }

        $uniqueId = (string) $this->opfXml['unique-identifier'];
        if (! $this->hasValue($this->metadata->getIdentifiers())) {
            $issues[] = $this->error('METADATA_IDENTIFIER_MISSING', 'The package has no dc:identifier.');
        } elseif ($uniqueId === '' || $this->query("/opf:package/opf:metadata/dc:identifier[@id='" . str_replace("'", '', $uniqueId) . "']") === []) {
            $issues[] = $this->error('METADATA_UNIQUE_IDENTIFIER', 'package@unique-identifier does not name a dc:identifier.', $uniqueId);
        }

        if ($this->isEpub3() && trim((string) $this->metadata->getProperty('dcterms:modified')) === '') {
            $issues[] = $this->error('METADATA_MODIFIED_MISSING', 'EPUB 3 packages need a dcterms:modified date.');
        }

        return $issues;
    }

    /**
     * @return list<ValidationIssue>
     */
    private function checkIds(): array
    {
        $counts = array_count_values(array_map(static fn (SimpleXMLElement $id): string => (string) $id, $this->query('//@id')));

        $issues = [];
        foreach ($counts as $id => $count) {
            if ($count > 1) {
                $issues[] = $this->error('DUPLICATE_ID', "The id \"{$id}\" is used {$count} times in the package document.", (string) $id);
            }
        }

        return $issues;
    }

    /**
     * @return list<ValidationIssue>
     */
    private function checkManifest(): array
    {
        $issues = [];
        foreach ($this->manifest->getItems() as $item) {
            if ($item->path === '') {
                if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $item->href) !== 1) {
                    $issues[] = $this->error('MANIFEST_HREF_OUTSIDE', "Manifest item \"{$item->id}\" points outside the book.", $item->href);
                }
            } elseif (! is_file($this->paths->resolve($this->rootDirectory, $item->path))) {
                $issues[] = $this->error('MANIFEST_FILE_MISSING', "Manifest item \"{$item->id}\" has no file.", $item->path);
            }
        }

        foreach ($this->bookFiles() as $path) {
            if (! $this->manifest->findByPath($path) instanceof ManifestItem) {
                $issues[] = $this->warning('FILE_NOT_IN_MANIFEST', 'The file is not listed in the manifest, so readers ignore it.', $path);
            }
        }

        return $issues;
    }

    /**
     * @return list<ValidationIssue>
     */
    private function checkSpine(): array
    {
        $items = $this->spine->getItems();
        if ($items === []) {
            return [$this->error('SPINE_EMPTY', 'The spine (reading order) has no items.')];
        }

        $issues = [];
        $seen = [];
        foreach ($items as $spineItem) {
            if (isset($seen[$spineItem->idref])) {
                $issues[] = $this->error('SPINE_DUPLICATE_IDREF', "The spine lists \"{$spineItem->idref}\" more than once.", $spineItem->idref);
                continue;
            }

            $seen[$spineItem->idref] = true;
            $item = $spineItem->item;
            if (! $item instanceof ManifestItem) {
                $issues[] = $this->error('SPINE_UNKNOWN_IDREF', "The spine refers to \"{$spineItem->idref}\", which is not in the manifest.", $spineItem->idref);
            } elseif (! in_array($item->mediaType, self::CONTENT_MEDIA_TYPES, true) && ! $this->hasFallback($item->id)) {
                $issues[] = $this->warning('SPINE_NOT_CONTENT', "Spine item \"{$item->id}\" is {$item->mediaType}, not a content document, and has no fallback.", $item->path);
            }
        }

        return $issues;
    }

    /**
     * XHTML content documents must be well-formed, and the local files they reference (images,
     * stylesheets, media, links to other documents) must exist and be in the manifest. Remote URLs,
     * data: URIs and links within the same document are not checked.
     *
     * @return list<ValidationIssue>
     */
    private function checkContent(): array
    {
        $issues = [];
        foreach ($this->manifest->getItems() as $item) {
            // The navigation document is checked by checkNavigation().
            $isNav = in_array('nav', explode(' ', $item->properties), true);
            if ($isNav || $item->mediaType !== 'application/xhtml+xml' || $item->path === '' || ! is_file($this->paths->resolve($this->rootDirectory, $item->path))) {
                continue;
            }

            try {
                $root = dom_import_simplexml($this->xmlParser->parse($this->paths->resolve($this->rootDirectory, $item->path)));
            } catch (XmlException $exception) {
                $issues[] = $this->error('CONTENT_NOT_WELL_FORMED', 'The content document is not well-formed XML: ' . $exception->getMessage(), $item->path);
                continue;
            }

            $directory = dirname($item->path) === '.' ? '' : dirname($item->path) . '/';
            $checked = [];
            foreach ($root instanceof \DOMElement ? $root->getElementsByTagName('*') : [] as $element) {
                foreach ($element->attributes ?? [] as $attribute) {
                    $target = $this->localReference($element->localName ?? '', $attribute->localName ?? '', $attribute->value, $directory);
                    if ($target !== null && ! isset($checked[$target])) {
                        $checked[$target] = true;
                        array_push($issues, ...$this->checkReference($item->path, $target));
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * The book-relative path a resource attribute refers to ("" when it leaves the book), or null
     * when the attribute is not a reference to a local file.
     */
    private function localReference(string $element, string $attribute, string $value, string $directory): ?string
    {
        $isReference = in_array($attribute, ['src', 'poster', 'data'], true)
            || ($attribute === 'href' && in_array($element, ['a', 'area', 'link', 'image', 'use'], true));
        $value = trim($value);
        if (! $isReference || $value === '' || str_starts_with($value, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $value) === 1) {
            return null;
        }

        $file = rawurldecode(explode('?', explode('#', $value, 2)[0], 2)[0]);
        try {
            return $file === '' ? null : $this->paths->normalize($directory . $file);
        } catch (InvalidEpubException) {
            return '';
        }
    }

    /**
     * @return list<ValidationIssue>
     */
    private function checkReference(string $document, string $target): array
    {
        if ($target === '' || ! is_file($this->paths->resolve($this->rootDirectory, $target))) {
            $where = $target === '' ? 'a file outside the book' : "{$target}, which does not exist";

            return [$this->error('CONTENT_REFERENCE_MISSING', "The content document refers to {$where}.", $document)];
        }

        return $this->manifest->findByPath($target) instanceof ManifestItem
            ? []
            : [$this->error('CONTENT_REFERENCE_NOT_IN_MANIFEST', "The content document refers to {$target}, which is not in the manifest.", $document)];
    }

    /**
     * @return list<ValidationIssue>
     */
    private function checkNavigation(): array
    {
        $issues = [];
        $items = $this->manifest->getItems();

        if ($this->isEpub3()) {
            $hasNav = array_filter($items, static fn (ManifestItem $item): bool => in_array('nav', explode(' ', $item->properties), true)) !== [];
            if (! $hasNav) {
                $issues[] = $this->error('NAV_MISSING', 'EPUB 3 books need a navigation document (a manifest item with the "nav" property).');
            }
        } elseif (array_filter($items, static fn (ManifestItem $item): bool => $item->mediaType === self::NCX_MEDIA_TYPE) === []) {
            $issues[] = $this->error('NCX_MISSING', 'EPUB 2 books need an NCX table of contents.');
        }

        foreach ($items as $item) {
            if ($item->path !== '' && is_file($this->paths->resolve($this->rootDirectory, $item->path))) {
                array_push($issues, ...$this->checkNavigationFile($item));
            }
        }

        try {
            $entries = $this->tableOfContents->getEntries();
        } catch (Exception) {
            // The navigation document or NCX is broken, which is reported above.
            $entries = [];
        }

        foreach ($this->tocPaths($entries) as $path) {
            if (! $this->manifest->findByPath($path) instanceof ManifestItem) {
                $issues[] = $this->error('TOC_LINK_NOT_IN_MANIFEST', 'The table of contents links to a file that is not in the manifest.', $path);
            }
        }

        return $issues;
    }

    /**
     * NAV_INVALID for a navigation document that is not well-formed XML; NCX_INVALID for an NCX
     * that is not, or lacks the NCX namespace (an unexpected default namespace is tolerated, as
     * when loading) or the navMap.
     *
     * @return list<ValidationIssue>
     */
    private function checkNavigationFile(ManifestItem $item): array
    {
        $isNav = in_array('nav', explode(' ', $item->properties), true);
        if (! $isNav && $item->mediaType !== self::NCX_MEDIA_TYPE) {
            return [];
        }

        try {
            $xml = $this->xmlParser->parse($this->paths->resolve($this->rootDirectory, $item->path));
        } catch (XmlException $exception) {
            return [$isNav
                ? $this->error('NAV_INVALID', 'The navigation document cannot be read: ' . $exception->getMessage(), $item->path)
                : $this->error('NCX_INVALID', 'The NCX cannot be read: ' . $exception->getMessage(), $item->path)];
        }

        $namespaces = $xml->getNamespaces(true);
        $namespace = in_array(self::NCX_NAMESPACE, $namespaces, true) ? self::NCX_NAMESPACE : ($namespaces[''] ?? null);
        if (! $isNav && ($namespace === null || $xml->children($namespace)->navMap->count() === 0)) {
            return [$this->error('NCX_INVALID', 'The NCX has no NCX namespace or no navMap.', $item->path)];
        }

        return [];
    }

    /**
     * @param list<TocEntry> $entries
     *
     * @return list<string>
     */
    private function tocPaths(array $entries): array
    {
        $paths = [];
        foreach ($entries as $entry) {
            if ($entry->path !== '') {
                $paths[] = $entry->path;
            }

            array_push($paths, ...$this->tocPaths($entry->children));
        }

        return array_values(array_unique($paths));
    }

    /**
     * Files of the publication (relative to the book root), without the container files.
     *
     * @return list<string>
     */
    private function bookFiles(): array
    {
        $root = (string) realpath($this->rootDirectory);
        $opfPath = $this->manifest->getOpfPath();
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->isFile() && $path !== 'mimetype' && $path !== $opfPath && ! str_starts_with($path, 'META-INF/')) {
                $files[] = $path;
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private function hasFallback(string $id): bool
    {
        return $this->query("/opf:package/opf:manifest/opf:item[@id='" . str_replace("'", '', $id) . "'][@fallback]") !== [];
    }

    /**
     * @param array<int, string> $values
     */
    private function hasValue(array $values): bool
    {
        return array_filter($values, static fn (string $value): bool => trim($value) !== '') !== [];
    }

    private function isEpub3(): bool
    {
        return str_starts_with($this->metadata->getVersion(), '3');
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function query(string $expression): array
    {
        $this->opfXml->registerXPathNamespace('opf', Metadata::OPF_NAMESPACE);
        $this->opfXml->registerXPathNamespace('dc', Metadata::DC_NAMESPACE);
        $result = $this->opfXml->xpath($expression);

        return $result === false || $result === null ? [] : array_values($result);
    }

    private function error(string $code, string $message, ?string $location = null): ValidationIssue
    {
        return new ValidationIssue(ValidationIssue::ERROR, $code, $message, $location);
    }

    private function warning(string $code, string $message, ?string $location = null): ValidationIssue
    {
        return new ValidationIssue(ValidationIssue::WARNING, $code, $message, $location);
    }
}
