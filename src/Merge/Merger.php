<?php

declare(strict_types=1);

namespace PhpEpub\Merge;

use PhpEpub\BookTemplate;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\FontObfuscation;
use PhpEpub\Landmark;
use PhpEpub\ManifestItem;
use PhpEpub\Metadata;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\LinkRemover;
use PhpEpub\Util\ModifiedDate;
use PhpEpub\Util\PathResolver;
use PhpEpub\Util\ReferenceRewriter;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;

/**
 * Combines several books (the volumes of a series, the chapters of a serial) into one EPUB 3 book.
 *
 * Every source book keeps its own directory in the merged book ("book-01/", "book-02/", …) with the layout it
 * had, so the references between its files stay valid; manifest ids get the same prefix. Spine order is
 * book order, then each book's own reading order (linear="no" is kept). Navigation documents and NCX files
 * of the sources are not copied: the merged book gets a new navigation document and NCX (see
 * MergeOptions::$oneSectionPerBook), and only the first book's cover landmark. Page lists, the EPUB 2 guide,
 * fallback chains and media overlay total durations are dropped. The package-level rendition settings are
 * the first book's page-progression direction and, when every book is pre-paginated, the layout; when
 * the layouts differ, the pre-paginated books' spine items get a per-item layout override instead.
 *
 * Fonts: obfuscation depends on the unique identifier, which changes. Obfuscated fonts are read de-obfuscated
 * (with their own book's key) and written obfuscated again with the IDPF algorithm and the merged book's key,
 * with entries in the new META-INF/encryption.xml. Fonts that were plain stay plain. Books that are DRM-protected
 * are refused.
 *
 * The books are only read; nothing in them or on their disk changes (unsaved edits are included).
 */
final readonly class Merger
{
    private const array XML_TYPES = ['application/xhtml+xml', 'image/svg+xml', 'application/smil+xml', 'text/html', 'application/xml', 'text/xml'];

    private const array FONT_TYPES = [
        'application/font-woff', 'application/font-woff2', 'application/x-font-ttf', 'application/x-font-otf',
        'application/x-font-opentype', 'application/vnd.ms-opentype', 'application/font-sfnt', 'application/x-font-woff',
    ];

    private const string NCX_TYPE = 'application/x-dtbncx+xml';

    public function __construct(
        private PathResolver $paths = new PathResolver(),
        private XmlParser $xmlParser = new XmlParser(),
        private ReferenceRewriter $rewriter = new ReferenceRewriter()
    ) {
    }

    /**
     * Merges the books and saves the result as $outputPath.
     *
     * @param list<EpubFile> $books Loaded books, in reading order: at least two, within the limits of $options.
     *
     * @throws Exception If there are too few or too many books, they are too large or DRM-protected, a book is not
     *                   loaded, a value is not valid XML text, or the merged book cannot be built or written.
     */
    public function merge(array $books, MergeOptions $options, string $outputPath): MergeReport
    {
        $bytes = $this->assertInputs($books, $options);
        $first = $books[0];
        $identifier = $options->identifier ?? BookTemplate::uuidUrn();
        $title = trim($options->title ?? $first->getMetadata()->getTitle());
        $title = $title === '' ? 'Untitled' : $title;
        $language = $options->language ?? ($this->languages($books)[0] ?? 'en');

        $merged = EpubFile::create($outputPath, $title, $language, $identifier);

        try {
            $report = $this->build($merged, $books, $options, $identifier, $bytes);
            ModifiedDate::save($merged, $outputPath, $options->clock);
        } finally {
            $merged->cleanup();
        }

        return $report;
    }

    /**
     * @param list<EpubFile> $books
     *
     * @return int The size of the books together.
     *
     * @throws Exception
     */
    private function assertInputs(array $books, MergeOptions $options): int
    {
        count($books) >= 2 || throw new Exception('Merging needs at least two books');
        count($books) <= $options->maxBooks || throw new Exception("Too many books to merge: {$options->maxBooks} at most, got " . count($books));

        $bytes = 0;
        $files = 0;
        foreach ($books as $number => $book) {
            $directory = $book->getTempDir() ?? throw new Exception('EPUB file must be loaded before merging.');
            if ($book->isDrmProtected()) {
                throw new Exception('Book ' . ($number + 1) . ' is DRM-protected, so its content cannot be merged.');
            }

            [$directoryBytes, $directoryFiles] = $this->measure($directory);
            $bytes += $directoryBytes;
            $files += $directoryFiles;
            $bytes <= $options->maxTotalBytes || throw new Exception("The books are too large to merge: {$options->maxTotalBytes} bytes at most");
            $files <= $options->maxTotalFiles || throw new Exception("The books have too many files to merge: {$options->maxTotalFiles} at most");
        }

        return $bytes;
    }

    /**
     * @return array{int, int} The size and the number of the files in a directory.
     */
    private function measure(string $directory): array
    {
        $bytes = 0;
        $count = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $bytes += (int) $file->getSize();
                ++$count;
            }
        }

        return [$bytes, $count];
    }

    /**
     * @param list<EpubFile> $books
     *
     * @return list<string>
     */
    private function languages(array $books): array
    {
        $languages = [];
        foreach ($books as $book) {
            foreach ($book->getMetadata()->getLanguages() as $language) {
                $language = trim($language);
                if ($language !== '' && ! in_array($language, $languages, true)) {
                    $languages[] = $language;
                }
            }
        }

        return $languages;
    }

    /**
     * @param list<EpubFile> $books
     *
     * @throws Exception
     */
    private function build(EpubFile $merged, array $books, MergeOptions $options, string $identifier, int $bytes): MergeReport
    {
        $outputDirectory = dirname($merged->getManifest()->getOpfPath());
        $outputDirectory = $outputDirectory === '.' ? '' : $outputDirectory;

        $files = [];
        foreach ($books as $index => $book) {
            $this->collect($book, $index, $outputDirectory, $files);
        }

        $deduplicated = $options->deduplicate ? $this->deduplicate($files) : 0;
        $directory = $merged->getTempDir() ?? throw new Exception('The merged book is not loaded.');
        [$pathMaps, $idMaps] = $this->write($merged, $directory, $books, $files, $identifier);

        $this->copyMetadata($merged, $books, $options);
        $this->copyReadingOrder($merged, $books, $idMaps);
        $this->copyOverlays($merged, $books, $files, $idMaps);
        $this->setCover($merged, $books[0], $options, $pathMaps[0], $idMaps[0]);
        $merged->getContentManager()->updateManifestProperties();
        $this->writeNavigation($merged, $books, $options, $pathMaps, $outputDirectory);

        return new MergeReport(count($books), count($files) - $deduplicated, $deduplicated, $bytes, $identifier);
    }

    /**
     * Reads the files of a book that go into the merged one.
     *
     * @param list<MergeFile> $files
     *
     * @throws Exception
     */
    private function collect(EpubFile $book, int $index, string $outputDirectory, array &$files): void
    {
        $directory = (string) $book->getTempDir();
        $manifest = $book->getManifest();
        $inSpine = array_flip($book->getSpine()->get());
        $opfDirectory = dirname($manifest->getOpfPath());
        $opfDirectory = $opfDirectory === '.' ? '' : $opfDirectory;

        // One translation for the whole book keeps its relative references valid: the files below the package
        // document move together, and a book with files outside that directory moves from its root instead.
        $base = $opfDirectory;
        $dropped = [];
        foreach ($manifest->getItems() as $item) {
            if ($item->path !== '' && $opfDirectory !== '' && ! str_starts_with($item->path, $opfDirectory . '/')) {
                $base = '';
            }

            if ($item->path !== '' && $this->isDropped($item, $inSpine)) {
                $dropped[$item->path] = true;
            }
        }

        $prefix = ($outputDirectory === '' ? '' : $outputDirectory . '/') . sprintf('book-%02d/', $index + 1);
        $obfuscated = $this->obfuscatedFonts($directory);
        $links = new LinkRemover($this->paths, $this->xmlParser);

        foreach ($manifest->getItems() as $item) {
            $fallback = $manifest->getFallback($item->id);
            if ($item->path === '' && preg_match('#^[a-z][a-z0-9+.-]*:#i', $item->href) === 1) {
                // A remote resource has no file; it is listed again with its new id.
                $files[] = new MergeFile($index, $item->id, '', '', $item->mediaType, $this->keptProperties($item), '', false, $item->href, $fallback);

                continue;
            }

            if ($item->path === '' || isset($dropped[$item->path])) {
                continue;
            }

            $isFont = isset($obfuscated[$item->path]);
            $content = null;
            if ($isFont) {
                try {
                    $content = $book->getContentManager()->getFontData($item->path);
                } catch (Exception) {
                    $isFont = false;
                }
            }

            $content ??= FileSystemHelper::readFile($this->paths->resolve($directory, $item->path));
            if ($content === null) {
                continue;
            }

            if ($item->mediaType === 'application/xhtml+xml') {
                $content = $this->withHtml5Doctype($content);
                // The navigation document of the book is not copied, so links to it would break.
                $content = $dropped === [] ? $content : ($links->remove($item->path, $content, $dropped) ?? $content);
            }

            $relative = $base === '' ? $item->path : substr($item->path, strlen($base) + 1);
            $files[] = new MergeFile($index, $item->id, $item->path, $prefix . $relative, $item->mediaType, $this->keptProperties($item), $content, $isFont, '', $fallback);
        }
    }

    /**
     * The NCX and a navigation document that is not in the reading order are replaced by the merged book's own.
     *
     * @param array<string, int> $inSpine
     */
    private function isDropped(ManifestItem $item, array $inSpine): bool
    {
        return $item->mediaType === self::NCX_TYPE
            || (in_array('nav', explode(' ', $item->properties), true) && ! isset($inSpine[$item->id]));
    }

    private function keptProperties(ManifestItem $item): string
    {
        return implode(' ', array_values(array_diff(explode(' ', $item->properties), ['', 'nav', 'cover-image'])));
    }

    /**
     * An XHTML 1.0 or 1.1 doctype is not allowed in EPUB 3. The DTD it names defines entities such as &nbsp;, which
     * the HTML5 doctype does not, so they become numeric character references.
     */
    private function withHtml5Doctype(string $content): string
    {
        $replaced = preg_replace('~<!DOCTYPE\s+html\s+PUBLIC\s+"[^"]*"\s+"[^"]*"\s*>~i', '<!DOCTYPE html>', $content, 1, $count);
        if ($replaced === null || $count === 0) {
            return $content;
        }

        return (string) preg_replace_callback('/&([A-Za-z][A-Za-z0-9]*);/', static function (array $match): string {
            if (in_array($match[1], ['amp', 'lt', 'gt', 'quot', 'apos'], true)) {
                return $match[0];
            }

            $character = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML401, 'UTF-8');

            return $character === $match[0] ? $match[0] : '&#' . mb_ord($character, 'UTF-8') . ';';
        }, $replaced);
    }

    /**
     * @return array<string, string> path => algorithm
     */
    private function obfuscatedFonts(string $directory): array
    {
        try {
            return (new FontObfuscation($directory, $this->xmlParser, $this->paths))->obfuscatedFonts();
        } catch (Exception) {
            return [];
        }
    }

    /**
     * Marks the files that repeat an earlier book's byte for byte, and drops the marks that cannot be honored because
     * a document that mentions the file cannot be rewritten.
     *
     * @param list<MergeFile> $files
     *
     * @return int How many files were left out.
     */
    private function deduplicate(array $files): int
    {
        $kept = [];
        foreach ($files as $position => $file) {
            if ($file->url !== '' || ! $this->isDeduplicable($file)) {
                continue;
            }

            $key = $file->mediaType . ':' . hash('sha256', $file->content);
            $other = $kept[$key] ?? null;
            if ($other === null) {
                $kept[$key] = $position;
            } elseif ($files[$other]->book !== $file->book) {
                $file->aliasOf = $other;
            }
        }

        foreach ($files as $document) {
            if ($document->url !== '' || $this->isBinary($document) || $this->isRewritable($document)) {
                continue;
            }

            foreach ($files as $alias) {
                if ($alias->aliasOf !== null && $this->mentions($document->content, $alias->newPath)) {
                    $alias->aliasOf = null;
                }
            }
        }

        $rewrites = [];
        foreach ($files as $alias) {
            if ($alias->aliasOf !== null) {
                $rewrites[] = [$alias->newPath, $files[$alias->aliasOf]->newPath];
            }
        }

        if ($rewrites === []) {
            return 0;
        }

        foreach ($files as $document) {
            if ($document->url === '' && $document->aliasOf === null && ! $this->isBinary($document) && $this->isRewritable($document)) {
                $document->content = $this->rewrite($document, $rewrites);
            }
        }

        return count($rewrites);
    }

    private function isDeduplicable(MergeFile $file): bool
    {
        if ($file->mediaType === 'text/css') {
            // A stylesheet's url() and @import values are relative to its own directory, so identical text can mean different files.
            return preg_match('/url\(|@import/i', $file->content) !== 1;
        }

        return $this->isFont($file) || (str_starts_with($file->mediaType, 'image/') && $file->mediaType !== 'image/svg+xml');
    }

    private function isFont(MergeFile $file): bool
    {
        return str_starts_with($file->mediaType, 'font/') || in_array($file->mediaType, self::FONT_TYPES, true)
            || in_array(strtolower(pathinfo($file->newPath, PATHINFO_EXTENSION)), ['ttf', 'otf', 'woff', 'woff2'], true);
    }

    private function isBinary(MergeFile $file): bool
    {
        return $this->isFont($file) || preg_match('~^(image/(?!svg)|audio/|video/|application/(pdf|octet-stream))~', $file->mediaType) === 1;
    }

    private function isRewritable(MergeFile $file): bool
    {
        if ($file->mediaType === 'text/css') {
            return true;
        }

        if (! in_array($file->mediaType, self::XML_TYPES, true)) {
            return false;
        }

        try {
            $this->xmlParser->parseString($file->content, $file->newPath);

            return true;
        } catch (XmlException) {
            return false;
        }
    }

    private function mentions(string $content, string $path): bool
    {
        $name = basename($path);

        return str_contains($content, $name) || str_contains($content, rawurlencode($name));
    }

    /**
     * @param list<array{string, string}> $rewrites [from, to] pairs of book-relative paths.
     */
    private function rewrite(MergeFile $document, array $rewrites): string
    {
        $content = $document->content;
        $isCss = $document->mediaType === 'text/css';
        foreach ($rewrites as [$from, $to]) {
            if (! $this->mentions($content, $from)) {
                continue;
            }

            $content = ($isCss
                ? $this->rewriter->rewriteCss($content, $document->newPath, $document->newPath, $from, $to)
                : $this->rewriter->rewriteXml($content, $document->newPath, $document->newPath, $from, $to)) ?? $content;
        }

        return $content;
    }

    /**
     * Writes the files, adds them to the manifest and returns, per book, the new path and the new id of every old one.
     *
     * @param list<EpubFile> $books
     * @param list<MergeFile> $files
     *
     * @return array{list<array<string, string>>, list<array<string, string>>}
     *
     * @throws Exception
     */
    private function write(EpubFile $merged, string $directory, array $books, array $files, string $identifier): array
    {
        $manifest = $merged->getManifest();
        $obfuscation = new FontObfuscation($directory, $this->xmlParser, $this->paths);
        $fontKey = (string) FontObfuscation::key(FontObfuscation::IDPF, $identifier);
        $pathMaps = array_fill(0, count($books), []);
        $idMaps = array_fill(0, count($books), []);
        $usedIds = [];
        $specs = [];

        foreach ($files as $file) {
            if ($file->aliasOf !== null) {
                continue;
            }

            $file->newId = $this->uniqueId($file, $usedIds);
            $spec = ['id' => $file->newId, 'mediaType' => $file->mediaType === '' ? null : $file->mediaType, 'properties' => $file->properties];
            if ($file->url !== '') {
                $specs[] = $spec + ['url' => $file->url];

                continue;
            }

            $stored = $file->obfuscated ? FontObfuscation::apply($file->content, FontObfuscation::IDPF, $fontKey) : $file->content;
            $target = $this->paths->resolve($directory, $file->newPath);
            (is_dir(dirname($target)) || @mkdir(dirname($target), 0700, true) || is_dir(dirname($target)))
                && @file_put_contents($target, $stored) !== false || throw new Exception("Failed to write: {$file->newPath}");

            $specs[] = $spec + ['path' => $file->newPath];
            if ($file->obfuscated) {
                $obfuscation->setAlgorithm($file->newPath, FontObfuscation::IDPF);
            }
        }

        $manifest->addMany($specs);

        foreach ($files as $file) {
            $kept = $file->aliasOf === null ? $file : $files[$file->aliasOf];
            if ($file->url === '') {
                $pathMaps[$file->book][$file->oldPath] = $kept->newPath;
            }

            $idMaps[$file->book][$file->oldId] = $kept->newId;
        }

        foreach ($files as $file) {
            $fallback = $file->aliasOf === null && $file->fallback !== null ? ($idMaps[$file->book][$file->fallback] ?? null) : null;
            if ($fallback !== null && $fallback !== $file->newId) {
                $manifest->setFallback($file->newId, $fallback);
            }
        }

        return [$pathMaps, $idMaps];
    }

    /**
     * @param array<string, true> $usedIds
     */
    private function uniqueId(MergeFile $file, array &$usedIds): string
    {
        $base = sprintf('b%02d-', $file->book + 1) . (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $file->oldId);
        $id = $base;
        for ($number = 2; isset($usedIds[$id]); $number++) {
            $id = $base . '-' . $number;
        }

        $usedIds[$id] = true;

        return $id;
    }

    /**
     * @param list<EpubFile> $books
     *
     * @throws Exception
     */
    private function copyMetadata(EpubFile $merged, array $books, MergeOptions $options): void
    {
        $metadata = $merged->getMetadata();
        $first = $books[0]->getMetadata();

        $authors = $options->authors ?? $first->getAuthors();
        if ($authors !== []) {
            $metadata->setAuthors(array_values($authors));
        }

        $languages = $this->languages($books);
        if ($options->language === null && count($languages) > 1) {
            try {
                $metadata->setLanguages($languages);
            } catch (Exception) {
                // A language that is not a BCP 47 tag stays out; the first book's is already the main one.
            }
        }

        $publisher = trim($first->getPublisher());
        if ($publisher !== '') {
            $metadata->setPublisher($publisher);
        }

        if (array_unique($this->layouts($books)) === ['pre-paginated']) {
            $metadata->setRenditionLayout('pre-paginated');
        }

        $this->copyAccessibility($metadata, $books);
    }

    /**
     * What a reader can rely on in the merged book: the access modes, features and hazards of all the books together
     * (a "none" or "unknown" hazard only when no book names a hazard) and their summaries one after the other.
     *
     * @param list<EpubFile> $books
     */
    private function copyAccessibility(Metadata $metadata, array $books): void
    {
        $modes = [];
        $features = [];
        $hazards = [];
        $summaries = [];
        foreach ($books as $book) {
            $source = $book->getMetadata();
            array_push($modes, ...$source->getAccessModes());
            array_push($features, ...$source->getAccessibilityFeatures());
            array_push($hazards, ...$source->getAccessibilityHazards());
            $summary = trim((string) $source->getAccessibilitySummary());
            if ($summary !== '') {
                $summaries[] = $summary;
            }
        }

        $named = array_diff($hazards, ['none', 'unknown']);
        $hazards = $named !== [] ? $named : array_slice($hazards, 0, 1);

        $modes === [] || $metadata->setAccessModes($this->unique($modes));
        $features === [] || $metadata->setAccessibilityFeatures($this->unique($features));
        $hazards === [] || $metadata->setAccessibilityHazards($this->unique($hazards));
        $summaries === [] || $metadata->setAccessibilitySummary(implode(' ', $this->unique($summaries)));
    }

    /**
     * @param array<int, string> $values
     *
     * @return list<string>
     */
    private function unique(array $values): array
    {
        return array_values(array_unique($values));
    }

    /**
     * @param list<EpubFile> $books
     *
     * @return list<string> "reflowable" or "pre-paginated" per book.
     */
    private function layouts(array $books): array
    {
        return array_map(static fn (EpubFile $book): string => $book->getMetadata()->getRenditionLayout() ?? 'reflowable', $books);
    }

    /**
     * @param list<EpubFile> $books
     * @param list<array<string, string>> $idMaps
     *
     * @throws Exception
     */
    private function copyReadingOrder(EpubFile $merged, array $books, array $idMaps): void
    {
        $layouts = $this->layouts($books);
        $mixed = count(array_unique($layouts)) > 1;

        $entries = [];
        $seen = [];
        foreach ($books as $index => $book) {
            foreach ($book->getSpine()->getItems() as $entry) {
                $id = $idMaps[$index][$entry->idref] ?? null;
                if ($id === null || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $properties = $entry->properties;
                $hasLayout = array_filter($properties, static fn (string $property): bool => str_starts_with($property, 'rendition:layout-')) !== [];
                if ($mixed && $layouts[$index] === 'pre-paginated' && ! $hasLayout) {
                    $properties[] = 'rendition:layout-pre-paginated';
                }

                $entries[] = ['idref' => $id, 'linear' => $entry->linear, 'properties' => $properties];
            }
        }

        $entries !== [] || throw new Exception('The books have no content to merge');
        $spine = $merged->getSpine();
        $spine->addMany($entries);

        $direction = $books[0]->getSpine()->getPageProgressionDirection();
        if ($direction !== null) {
            $spine->setPageProgressionDirection($direction);
        }
    }

    /**
     * Media overlays and their durations follow their documents; the book's total duration is the sum of the overlays'
     * (left out when one of them has none, or one is not a clock value this can add up).
     *
     * @param list<EpubFile> $books
     * @param list<MergeFile> $files
     * @param list<array<string, string>> $idMaps
     *
     * @throws Exception
     */
    private function copyOverlays(EpubFile $merged, array $books, array $files, array $idMaps): void
    {
        $seconds = [];
        foreach ($files as $file) {
            $book = $books[$file->book];
            if ($file->aliasOf !== null || $file->url !== '' || $file->mediaType !== 'application/xhtml+xml' || ! str_starts_with($book->getMetadata()->getVersion(), '3')) {
                continue;
            }

            $overlay = $book->getManifest()->getMediaOverlay($file->oldId);
            $overlayId = $overlay === null ? null : ($idMaps[$file->book][$overlay] ?? null);
            if ($overlay === null || $overlayId === null) {
                continue;
            }

            $merged->getManifest()->setMediaOverlay($file->newId, $overlayId);
            $duration = $book->getMetadata()->getMediaDurationOf($overlay);
            if ($duration !== null) {
                $merged->getMetadata()->setMediaDurationOf($overlayId, $duration);
            }

            $seconds[$overlayId] = $duration === null ? null : $this->clockSeconds($duration);
        }

        if ($seconds !== [] && ! in_array(null, $seconds, true)) {
            $merged->getMetadata()->setMediaDuration(sprintf('%.3Fs', array_sum($seconds)));
        }
    }

    /**
     * A SMIL clock value in seconds, or null when it is not one this understands.
     */
    private function clockSeconds(string $clock): ?float
    {
        $clock = trim($clock);
        if (preg_match('/^(\d+(?:\.\d+)?)(h|min|s|ms)?$/', $clock, $match) === 1) {
            return (float) $match[1] * match ($match[2] ?? 's') {
                'h' => 3600.0,
                'min' => 60.0,
                'ms' => 0.001,
                default => 1.0,
            };
        }

        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2}(?:\.\d+)?)$/', $clock, $match) === 1) {
            return (float) $match[1] * 3600 + (float) $match[2] * 60 + (float) $match[3];
        }

        return null;
    }

    /**
     * @param array<string, string> $pathMap
     * @param array<string, string> $idMap
     *
     * @throws Exception
     */
    private function setCover(EpubFile $merged, EpubFile $first, MergeOptions $options, array $pathMap, array $idMap): void
    {
        if ($options->coverImage !== null) {
            $merged->setCoverImage($options->coverImage, $options->coverMediaType);

            return;
        }

        $cover = $first->getCoverImage();
        $newId = $cover instanceof ManifestItem && str_starts_with($cover->mediaType, 'image/') && isset($pathMap[$cover->path]) ? ($idMap[$cover->id] ?? null) : null;
        if ($newId !== null) {
            $merged->getManifest()->addProperty($newId, 'cover-image');
            $merged->getMetadata()->setMeta('cover', $newId);
        }
    }

    /**
     * @param list<EpubFile> $books
     * @param list<array<string, string>> $pathMaps
     *
     * @throws Exception
     */
    private function writeNavigation(EpubFile $merged, array $books, MergeOptions $options, array $pathMaps, string $outputDirectory): void
    {
        $ncxPath = ($outputDirectory === '' ? '' : $outputDirectory . '/') . 'toc.ncx';
        $ncx = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head/><docTitle><text/></docTitle><navMap/></ncx>' . "\n";
        $target = $this->paths->resolve((string) $merged->getTempDir(), $ncxPath);
        @file_put_contents($target, $ncx) !== false || throw new Exception("Failed to write: {$ncxPath}");
        $merged->getManifest()->add($ncxPath, self::NCX_TYPE, 'ncx');
        $merged->getSpine()->setToc('ncx');

        $entries = [];
        foreach ($books as $index => $book) {
            $title = trim($book->getMetadata()->getTitle());
            $title = $title === '' ? 'Book ' . ($index + 1) : $title;
            $firstPath = null;
            foreach ($book->getSpine()->get() as $idref) {
                $path = $book->getManifest()->get($idref)?->path;
                if ($path !== null && isset($pathMaps[$index][$path])) {
                    $firstPath = $pathMaps[$index][$path];
                    break;
                }
            }

            try {
                $sourceEntries = $book->getTableOfContents()->getEntries();
            } catch (Exception) {
                $sourceEntries = [];
            }

            $mapped = $this->mapEntries($sourceEntries, $pathMaps[$index]);
            if ($options->oneSectionPerBook) {
                $entries[] = new TocEntry($title, $firstPath ?? '', null, $mapped);
            } elseif ($mapped !== []) {
                array_push($entries, ...$mapped);
            } elseif ($firstPath !== null) {
                $entries[] = new TocEntry($title, $firstPath);
            }
        }

        $toc = $merged->getTableOfContents();
        $toc->setEntries($entries);

        try {
            $covers = array_values(array_filter(
                $books[0]->getTableOfContents()->getLandmarks(),
                static fn (Landmark $landmark): bool => $landmark->type === 'cover'
            ));
        } catch (Exception) {
            $covers = [];
        }

        $path = isset($covers[0]) ? ($pathMaps[0][$covers[0]->path] ?? null) : null;
        if (isset($covers[0]) && $path !== null && trim($covers[0]->title) !== '') {
            $toc->setLandmarks([new Landmark('cover', $covers[0]->title, $path, $covers[0]->fragment)]);
        }
    }

    /**
     * @param list<TocEntry> $entries
     * @param array<string, string> $pathMap
     *
     * @return list<TocEntry>
     */
    private function mapEntries(array $entries, array $pathMap): array
    {
        $mapped = [];
        foreach ($entries as $entry) {
            $children = $this->mapEntries($entry->children, $pathMap);
            $path = $entry->path === '' ? null : ($pathMap[$entry->path] ?? null);
            if ($path !== null) {
                $mapped[] = new TocEntry($entry->title, $path, $entry->fragment, $children);
            } elseif ($children !== []) {
                $mapped[] = new TocEntry($entry->title, '', null, $children);
            }
        }

        return $mapped;
    }
}
