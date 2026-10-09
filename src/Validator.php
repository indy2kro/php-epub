<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\ContentDocumentProperties;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\MetadataSyntax;
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
     * Image media types whose declaration is compared with the file content.
     */
    private const array CHECKED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * How much of a file is read to recognise its type; larger files are not loaded for that.
     */
    private const int SNIFF_BYTES = 524288;

    /**
     * XHTML documents above this size are not parsed for the manifest properties they need.
     */
    private const int MAX_DOCUMENT_BYTES = 8388608;

    /**
     * The encrypted (DRM) resources of the book, as keys: their content cannot be examined.
     *
     * @var array<string, int>
     */
    private array $encrypted;

    private Encryption $encryption;

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
        $this->encryption = new Encryption($rootDirectory, $xmlParser, $paths);
        $this->encrypted = array_flip($this->encryption->encryptedPaths());
    }

    /**
     * @return list<ValidationIssue> Errors and warnings, grouped by area (container, metadata, ids, manifest, spine,
     *                               content documents, navigation).
     */
    public function validate(): array
    {
        return [
            ...$this->checkMimetype(),
            ...$this->checkFileNames(),
            ...$this->checkEncryption(),
            ...$this->checkMetadata(),
            ...$this->checkMetadataSyntax(),
            ...$this->checkIds(),
            ...$this->checkManifest(),
            ...$this->checkSpine(),
            ...$this->checkContent(),
            ...$this->checkNavigation(),
            ...$this->checkMediaTypes(),
            ...$this->checkManifestProperties(),
            ...$this->checkAccessibility(),
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
     * A DRM-protected book is one error: its encrypted content is not examined by the checks below
     * (it would be reported as not well-formed, with the wrong media type, and so on).
     *
     * @return list<ValidationIssue>
     */
    private function checkEncryption(): array
    {
        if (! $this->encryption->isDrmProtected()) {
            return [];
        }

        $count = count($this->encrypted);

        return [$this->error(
            'CONTENT_ENCRYPTED',
            'The book is DRM-protected' . ($count === 0 ? '' : ", and {$count} of its resources are encrypted") . '; they cannot be read or checked.',
            $count === 0 ? null : 'META-INF/encryption.xml'
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
     * Languages must be well-formed BCP 47 tags (an error: EPUBCheck rejects them) and dates W3CDTF
     * (a warning: reading systems cope). Empty languages are reported by checkMetadata().
     *
     * @return list<ValidationIssue>
     */
    private function checkMetadataSyntax(): array
    {
        $issues = [];
        foreach ($this->metadata->getLanguages() as $language) {
            $language = trim($language);
            if ($language !== '' && ! MetadataSyntax::isLanguageTag($language)) {
                $issues[] = $this->error('METADATA_LANGUAGE_INVALID', "\"{$language}\" is not a well-formed BCP 47 language tag.", $language);
            }
        }

        foreach ($this->metadata->getDublinCoreValues('date') as $date) {
            $date = trim($date);
            if (! MetadataSyntax::isW3cdtf($date)) {
                $issues[] = $this->warning('METADATA_DATE_INVALID', "\"{$date}\" is not a W3CDTF date (YYYY, YYYY-MM, YYYY-MM-DD or a date-time with a time zone).", $date);
            }
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
            if ($isNav || $item->mediaType !== 'application/xhtml+xml' || $item->path === '' || isset($this->encrypted[$item->path]) || ! is_file($this->paths->resolve($this->rootDirectory, $item->path))) {
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
     * The media type of an image (JPEG, PNG, GIF, WebP) must match its content, and an XHTML document
     * must not obviously be something else (EPUBCheck OPF-029 and friends). Only the first bytes of a
     * file are read; content that cannot be recognised is not judged.
     *
     * @return list<ValidationIssue>
     */
    private function checkMediaTypes(): array
    {
        $issues = [];
        foreach ($this->manifest->getItems() as $item) {
            $isImage = in_array($item->mediaType, self::CHECKED_IMAGE_TYPES, true);
            if ($item->path === '' || isset($this->encrypted[$item->path]) || ! ($isImage || $item->mediaType === 'application/xhtml+xml')) {
                continue;
            }

            $file = $this->paths->resolve($this->rootDirectory, $item->path);
            if (! is_file($file)) {
                continue;
            }

            $prefix = (string) @file_get_contents($file, false, null, 0, self::SNIFF_BYTES);
            if ($isImage) {
                $size = @getimagesizefromstring($prefix);
                $detected = $size === false ? $item->mediaType : $size['mime'];
                $message = "Manifest item \"{$item->id}\" is declared {$item->mediaType}, but its content is {$detected}.";
                $mismatch = $detected !== $item->mediaType;
            } else {
                $message = "Manifest item \"{$item->id}\" is declared application/xhtml+xml, but its content does not look like XML.";
                $mismatch = $this->isObviouslyNotXml($prefix);
            }

            if ($mismatch) {
                $issues[] = $this->error('MEDIA_TYPE_MISMATCH', $message, $item->path);
            }
        }

        return $issues;
    }

    /**
     * Whether the first bytes of a document cannot start an XML document: the first character after an
     * optional UTF-8 byte order mark and white space is not "<". Empty files and UTF-16 documents are not judged.
     */
    private function isObviouslyNotXml(string $prefix): bool
    {
        $start = ltrim(str_starts_with($prefix, "\xEF\xBB\xBF") ? substr($prefix, 3) : $prefix);

        return $start !== '' && ! in_array(substr($start, 0, 2), ["\xFE\xFF", "\xFF\xFE"], true) && $start[0] !== '<';
    }

    /**
     * EPUB 3 XHTML documents must declare the properties their content needs (svg, mathml, scripted,
     * remote-resources): an error when one is missing (EPUBCheck OPF-014), a warning when one is not needed.
     * A cover-image property belongs to an image only (OPF-012). Documents above a size limit, and
     * documents that are not well-formed (reported by checkContent()), are not examined.
     *
     * @return list<ValidationIssue>
     */
    private function checkManifestProperties(): array
    {
        $issues = [];
        foreach ($this->manifest->getItems() as $item) {
            $declared = preg_split('/\s+/', trim($item->properties), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (in_array('cover-image', $declared, true) && ! str_starts_with($item->mediaType, 'image/')) {
                $issues[] = $this->error('COVER_NOT_IMAGE', "Manifest item \"{$item->id}\" has the cover-image property but is {$item->mediaType}, not an image.", $item->path);
            }

            $needed = $this->isEpub3() ? $this->neededProperties($item) : null;
            if ($needed === null) {
                continue;
            }

            foreach (ContentDocumentProperties::PROPERTIES as $property) {
                if (in_array($property, $needed, true) && ! in_array($property, $declared, true)) {
                    $issues[] = $this->error('MANIFEST_PROPERTY_MISSING', "Manifest item \"{$item->id}\" needs the \"{$property}\" property.", $item->path);
                } elseif (! in_array($property, $needed, true) && in_array($property, $declared, true)) {
                    $issues[] = $this->warning('MANIFEST_PROPERTY_UNNEEDED', "Manifest item \"{$item->id}\" has the \"{$property}\" property, but its content does not need it.", $item->path);
                }
            }
        }

        return $issues;
    }

    /**
     * The properties an XHTML manifest item needs; null for another kind of item, or when its file is
     * missing, too large or not well-formed.
     *
     * @return list<string>|null
     */
    private function neededProperties(ManifestItem $item): ?array
    {
        $file = $item->path === '' ? '' : $this->paths->resolve($this->rootDirectory, $item->path);
        $isReadable = $item->mediaType === 'application/xhtml+xml' && ! isset($this->encrypted[$item->path]) && is_file($file)
            && (int) @filesize($file) <= self::MAX_DOCUMENT_BYTES;
        $content = $isReadable ? FileSystemHelper::readFile($file) : null;

        return $content === null ? null : ContentDocumentProperties::detect($content, $this->xmlParser);
    }

    /**
     * EPUB 3 books should describe their accessibility (EPUB Accessibility 1.1; the European Accessibility
     * Act asks for it): access modes, features, hazards and a summary. Missing metadata is a warning.
     *
     * @return list<ValidationIssue>
     */
    private function checkAccessibility(): array
    {
        if (! $this->isEpub3()) {
            return [];
        }

        $metadata = [
            'ACCESSIBILITY_ACCESS_MODE_MISSING' => ['schema:accessMode', $this->metadata->getAccessModes()],
            'ACCESSIBILITY_FEATURE_MISSING' => ['schema:accessibilityFeature', $this->metadata->getAccessibilityFeatures()],
            'ACCESSIBILITY_HAZARD_MISSING' => ['schema:accessibilityHazard', $this->metadata->getAccessibilityHazards()],
            'ACCESSIBILITY_SUMMARY_MISSING' => ['schema:accessibilitySummary', [(string) $this->metadata->getAccessibilitySummary()]],
        ];

        $issues = [];
        foreach ($metadata as $code => [$property, $values]) {
            if (! $this->hasValue($values)) {
                $issues[] = $this->warning($code, "The package has no {$property} accessibility metadata.");
            }
        }

        return $issues;
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
            if ($item->path !== '' && ! isset($this->encrypted[$item->path]) && is_file($this->paths->resolve($this->rootDirectory, $item->path))) {
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

        if (! $isNav && $xml->children($namespace)->navMap->children($namespace)->navPoint->count() === 0) {
            return [$this->error('NCX_EMPTY', 'The NCX navMap has no navPoint.', $item->path)];
        }

        return $isNav && $this->hasEmptyTocList($xml) ? [$this->error('NAV_EMPTY', 'The navigation document\'s toc has no list item.', $item->path)] : [];
    }

    /**
     * Whether the navigation document's toc nav has a list without any item.
     */
    private function hasEmptyTocList(SimpleXMLElement $xml): bool
    {
        $root = dom_import_simplexml($xml);
        $xpath = new \DOMXPath($root->ownerDocument ?? new \DOMDocument());
        $xpath->registerNamespace('x', (string) $root->namespaceURI);
        $xpath->registerNamespace('epub', 'http://www.idpf.org/2007/ops');

        $lists = $xpath->query("//x:nav[contains(concat(' ', normalize-space(@epub:type), ' '), ' toc ')]/x:ol");
        foreach ($lists === false ? [] : $lists as $list) {
            if ($list instanceof \DOMElement && $list->getElementsByTagNameNS('*', 'li')->length === 0) {
                return true;
            }
        }

        return false;
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

    /**
     * OCF forbids the characters " * : < > ? \, DEL, C0 controls and a trailing "." in file names;
     * such files cannot be created on every system.
     *
     * @return list<ValidationIssue>
     */
    private function checkFileNames(): array
    {
        $root = (string) realpath($this->rootDirectory);
        $issues = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if (preg_match('#["*:<>?\\\\\x00-\x1f\x7f]|\.(/|$)#', $path) === 1) {
                $issues[] = $this->warning('FILE_NAME_INVALID', 'The file name contains a character OCF forbids or ends with a dot.', $path);
            }
        }

        return $issues;
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
