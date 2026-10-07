<?php

declare(strict_types=1);

namespace PhpEpub;

use DOMDocument;
use PhpEpub\Converters\ConverterInterface;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\PathResolver;
use PhpEpub\Util\XhtmlFragment;
use PhpEpub\Util\XmlText;
use SimpleXMLElement;
use Throwable;

class EpubFile
{
    private const string COVER_PROPERTY = 'cover-image';

    private const array IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
    ];

    private ?string $tempDir = null;

    /**
     * False for a book opened from a string or stream: there is no file to reload or overwrite.
     */
    private bool $hasFile = true;
    private readonly ZipHandler $zipHandler;
    private readonly XmlParser $xmlParser;
    private readonly Parser $parser;
    private ?Metadata $metadata = null;
    private ?Spine $spine = null;
    private ?Manifest $manifest = null;
    private ?SimpleXMLElement $opfXml = null;
    private ?ContentManager $contentManager = null;

    /**
     * The unique identifier the book's obfuscated fonts are keyed with (as loaded or last saved).
     */
    private ?string $fontKeyIdentifier = null;

    public function __construct(
        private readonly string $filePath,
        ?ZipHandler $zipHandler = null,
        ?XmlParser $xmlParser = null
    ) {
        $this->zipHandler = $zipHandler ?? new ZipHandler();
        $this->xmlParser = $xmlParser ?? new XmlParser();
        $this->parser = new Parser($this->xmlParser);
    }

    /**
     * Creates an EpubFile and loads it.
     *
     * @throws Exception If the file cannot be extracted or is not a valid EPUB.
     */
    public static function open(string $filePath, ?ZipHandler $zipHandler = null, ?XmlParser $xmlParser = null): self
    {
        $epubFile = new self($filePath, $zipHandler, $xmlParser);
        $epubFile->load();

        return $epubFile;
    }

    /**
     * Opens a book held in a string, e.g. an upload or an HTTP download. The ZIP limits apply as for files.
     * Such a book has no file: save() needs a path, or use saveToString() or saveToStream().
     *
     * @throws Exception If the data is not a valid EPUB or a limit is exceeded.
     */
    public static function openString(string $data, ?ZipHandler $zipHandler = null, ?XmlParser $xmlParser = null): self
    {
        return self::openArchive(
            static fn (string $archive): bool => @file_put_contents($archive, $data) !== false || throw new Exception('Failed to buffer the EPUB data'),
            $zipHandler,
            $xmlParser
        );
    }

    /**
     * Opens a book read from a stream (from its current position to its end), e.g. a PHP input
     * stream or a download. The stream stays open and is not rewound. The ZIP limits apply as for files.
     *
     * @param resource $stream A readable stream.
     *
     * @throws Exception If the stream cannot be read, the data is not a valid EPUB or a limit is exceeded.
     */
    public static function openStream($stream, ?ZipHandler $zipHandler = null, ?XmlParser $xmlParser = null): self
    {
        self::assertStream($stream);

        return self::openArchive(
            static function (string $archive) use ($stream): void {
                $output = @fopen($archive, 'wb') ?: throw new Exception('Failed to buffer the EPUB stream');
                $copied = @stream_copy_to_stream($stream, $output);
                fclose($output);
                $copied === false && throw new Exception('Failed to read the EPUB stream');
            },
            $zipHandler,
            $xmlParser
        );
    }

    /**
     * @param \Closure(string): mixed $write Writes the archive to the given path.
     */
    private static function openArchive(\Closure $write, ?ZipHandler $zipHandler, ?XmlParser $xmlParser): self
    {
        $epubFile = new self('', $zipHandler, $xmlParser);
        $epubFile->hasFile = false;

        self::withScratchArchive(static function (string $archive) use ($write, $epubFile): void {
            $write($archive);
            $epubFile->openWith(fn (string $directory) => $epubFile->zipHandler->extract($archive, $directory));
        });

        return $epubFile;
    }

    /**
     * Runs $use with the path of an archive inside a private (0700, randomly named) directory
     * that is deleted afterwards, whatever happens: ZipArchive needs a real file.
     *
     * @template T
     *
     * @param \Closure(string): T $use
     *
     * @return T
     */
    private static function withScratchArchive(\Closure $use): mixed
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epubio_' . bin2hex(random_bytes(16));
        @mkdir($directory, 0700) || throw new Exception("Failed to create temporary directory: {$directory}");

        try {
            return $use($directory . DIRECTORY_SEPARATOR . 'book.epub');
        } finally {
            (new FileSystemHelper())->deleteDirectory($directory);
        }
    }

    /**
     * @throws Exception If $stream is not a stream resource.
     */
    private static function assertStream(mixed $stream): void
    {
        is_resource($stream) && get_resource_type($stream) === 'stream' || throw new Exception('A stream resource is required');
    }

    public function __destruct()
    {
        // A destructor must not throw; call cleanup() explicitly to see a failure.
        $this->cleanupQuietly();
    }

    /**
     * A clone would share the extracted book, and the first destructor would delete it
     * under the other instance. Open the file again (or save() a copy and open that) instead.
     *
     * @throws Exception Always.
     */
    public function __clone()
    {
        // The clone has no extraction of its own; keep its destructor away from the original's files.
        $this->tempDir = null;

        throw new Exception('EpubFile cannot be cloned; open the file again instead.');
    }

    /**
     * Deletes the extracted book. The EpubFile is then unloaded: call load() before using it again.
     */
    public function cleanup(): void
    {
        // These objects point into the extraction; drop them so the accessors fail clearly.
        $this->opfXml = null;
        $this->metadata = null;
        $this->manifest = null;
        $this->spine = null;
        $this->contentManager = null;

        $tempDir = $this->tempDir;
        if ($tempDir === null) {
            return;
        }

        if (! (new FileSystemHelper())->deleteDirectory($tempDir)) {
            // Keep the path, so a later cleanup() (or the destructor) can retry.
            throw new Exception("Failed to delete the extracted EPUB: {$tempDir}");
        }

        $this->tempDir = null;
    }

    /**
     * Extracts and parses the file, replacing the loaded book (and discarding its unsaved changes).
     *
     * @throws Exception If the file cannot be loaded. A loaded book whose file does not exist yet
     *                   (made with create() and not saved) is kept.
     */
    public function load(): void
    {
        if (! $this->hasFile) {
            throw new Exception('This book was opened from a string or stream and has no file to load again; open it again instead.');
        }

        if ($this->tempDir !== null && ! is_file($this->filePath)) {
            throw new Exception("Nothing to load from {$this->filePath}: the file does not exist yet; save() the book first.");
        }

        $this->openWith(fn (string $directory) => $this->zipHandler->extract($this->filePath, $directory));
    }

    /**
     * Creates a new, empty EPUB 3 book (with a navigation document) that save() writes to $filePath.
     * Nothing is written to $filePath before save(). Add content with addChapter(); a book needs
     * at least one chapter to be valid.
     *
     * @param string|null $identifier The unique identifier; a random "urn:uuid:…" when null.
     *
     * @throws Exception If a value is empty or not valid XML text, or the book cannot be prepared.
     */
    public static function create(string $filePath, string $title, string $language = 'en', ?string $identifier = null): self
    {
        $identifier ??= BookTemplate::uuidUrn();
        XmlText::assertValid($title, $language, $identifier);
        if (trim($title) === '' || trim($language) === '' || trim($identifier) === '') {
            throw new Exception('A new book needs a title, a language and an identifier');
        }

        $epubFile = new self($filePath);
        $epubFile->openWith(static function (string $directory) use ($title, $language, $identifier): void {
            $files = [
                'mimetype' => 'application/epub+zip',
                'META-INF/container.xml' => BookTemplate::container('EPUB/package.opf'),
                'EPUB/package.opf' => BookTemplate::package($title, $language, $identifier, 'nav.xhtml'),
                'EPUB/nav.xhtml' => BookTemplate::navigation($title, $language),
            ];

            foreach ($files as $path => $content) {
                $target = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                $written = (is_dir(dirname($target)) || @mkdir(dirname($target), 0700, true)) && @file_put_contents($target, $content) !== false;
                $written || throw new Exception("Failed to prepare the new book: {$path}");
            }
        });

        return $epubFile;
    }

    /**
     * Adds an XHTML chapter: writes the document, adds it to the manifest and the reading order,
     * and appends it to the table of contents when the book has one.
     *
     * @param string $body The chapter's body markup. Well-formed XHTML is inserted as it is; markup that is not
     *                     (e.g. "&nbsp;", "<br>" or unclosed tags) is parsed as an HTML fragment and written as XHTML.
     * @param string|null $path Path relative to the book root; defaults to "text/chapter-N.xhtml" next to the OPF.
     *
     * @throws Exception If the book is not loaded, the title or body is not valid XML text, or a file cannot be written.
     */
    public function addChapter(string $title, string $body, ?string $path = null): ManifestItem
    {
        XmlText::assertValid($title, $body);
        $manifest = $this->getManifest();
        $spine = $this->getSpine();
        $path ??= $this->unusedChapterPath();
        $language = $this->getMetadata()->getLanguage();
        $language = $language === '' ? 'en' : $language;

        try {
            $this->xmlParser->parseString(BookTemplate::chapter($title, $language, $body));
        } catch (XmlException) {
            $body = XhtmlFragment::fromHtml($body);
        }

        $this->getContentManager()->addContent($path, BookTemplate::chapter($title, $language, $body));
        $item = $manifest->findByPath($path) ?? throw new Exception("The chapter is not in the manifest: {$path}");

        if (! $spine->contains($item->id)) {
            $spine->add($item->id);
        }

        $toc = $this->getTableOfContents();
        if ($toc->isAvailable()) {
            $toc->addEntry(new TocEntry($title, $item->path));
        }

        return $item;
    }

    /**
     * "text/chapter-N.xhtml" next to the OPF, with the first N not used by a file or manifest item.
     */
    private function unusedChapterPath(): string
    {
        $manifest = $this->getManifest();
        $directory = dirname($manifest->getOpfPath());
        $base = ($directory === '.' ? '' : $directory . '/') . 'text/chapter-';

        for ($number = 1;; $number++) {
            $path = $base . $number . '.xhtml';
            $file = (string) $this->tempDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (! $manifest->findByPath($path) instanceof ManifestItem && ! file_exists($file)) {
                return $path;
            }
        }
    }

    /**
     * Prepares a private temporary directory, lets $fill put a book into it, and opens the book.
     *
     * @param \Closure(string): void $fill
     *
     * @throws Exception
     */
    private function openWith(\Closure $fill): void
    {
        // Loading again starts from the file on disk; drop the previous extraction
        // (if it cannot be deleted, it must not stop the new book from loading).
        $this->cleanupQuietly();

        // Unpredictable name and owner-only permissions: the extracted book may be private.
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_' . bin2hex(random_bytes(16));
        $this->tempDir = $directory;
        @mkdir($directory, 0700) || throw new Exception("Failed to create temporary directory: {$directory}");

        try {
            $fill($directory);

            $opfFilePath = $this->parser->parse($directory);
            $opfFileFullPath = $directory . DIRECTORY_SEPARATOR . $opfFilePath;

            $this->opfXml = $this->xmlParser->parse($opfFileFullPath);

            $this->metadata = new Metadata($this->opfXml, $opfFileFullPath);
            $this->fontKeyIdentifier = $this->metadata->getUniqueIdentifier();
            $this->manifest = new Manifest($this->opfXml, $opfFilePath);
            $this->spine = new Spine($this->opfXml, $this->manifest);
            $this->contentManager = new ContentManager($directory, $this->manifest, $this->spine);
        } catch (Throwable $throwable) {
            // Do not leave a half-loaded book (or its extracted files) behind,
            // and report why loading failed rather than a cleanup problem.
            $this->cleanupQuietly();

            throw $throwable;
        }
    }

    public function save(?string $filePath = null): void
    {
        $tempDir = $this->tempDir;
        if ($tempDir === null) {
            throw new Exception('EPUB file must be loaded before saving.');
        }

        if ($filePath === null) {
            $this->hasFile || throw new Exception('This book was opened from a string or stream and has no file to overwrite: pass a path to save(), or use saveToString() or saveToStream().');
            $filePath = $this->filePath;
        }

        $this->writePackage();

        // Books with a missing or padded mimetype load (see Parser::parse()), but the saved one must be exact.
        $mimetype = $tempDir . DIRECTORY_SEPARATOR . 'mimetype';
        FileSystemHelper::readFile($mimetype) === 'application/epub+zip'
            || @file_put_contents($mimetype, 'application/epub+zip') !== false
            || throw new Exception("Failed to write the mimetype file: {$mimetype}");

        $this->zipHandler->compress($tempDir, $filePath);
    }

    /**
     * Packages the book (as save() does) and returns the EPUB file's bytes. Works for any loaded book.
     *
     * @throws Exception If the book is not loaded or cannot be packaged.
     */
    public function saveToString(): string
    {
        return self::withScratchArchive(function (string $archive): string {
            $this->save($archive);

            return FileSystemHelper::readFile($archive) ?? throw new Exception('Failed to read the saved EPUB');
        });
    }

    /**
     * Packages the book (as save() does) and writes it to a stream at its current position.
     * The stream stays open.
     *
     * @param resource $stream A writable stream.
     *
     * @throws Exception If the stream is not writable, the book is not loaded or cannot be packaged.
     */
    public function saveToStream($stream): void
    {
        self::assertStream($stream);

        self::withScratchArchive(function (string $archive) use ($stream): void {
            $this->save($archive);

            $input = @fopen($archive, 'rb') ?: throw new Exception('Failed to read the saved EPUB');
            $copied = @stream_copy_to_stream($input, $stream);
            fclose($input);
            $copied === filesize($archive) || throw new Exception('Failed to write the EPUB to the stream');
        });
    }

    /**
     * Converts the book with the given adapter, including changes that have not been saved yet.
     *
     * @throws Exception If the book is not loaded or the conversion fails.
     */
    public function convert(ConverterInterface $converter, string $outputPath): void
    {
        $tempDir = $this->tempDir;
        if ($tempDir === null) {
            throw new Exception('EPUB file must be loaded before converting.');
        }

        $this->writePackage();
        $converter->convert($tempDir, $outputPath);
    }

    /**
     * Returns the cover image, looking in order at: the manifest item with the EPUB 3
     * "cover-image" property; the item named by the EPUB 2 <meta name="cover"> (by id, or
     * by href as some books write it); the EPUB 2 <guide> cover reference, which names
     * either the image or a cover page whose first image is used.
     */
    public function getCoverImage(): ?ManifestItem
    {
        $manifest = $this->getManifest();

        foreach ($manifest->getItems() as $item) {
            if (in_array(self::COVER_PROPERTY, explode(' ', $item->properties), true)) {
                return $item;
            }
        }

        $cover = $this->getMetadata()->getMeta('cover');
        if ($cover !== null) {
            $item = $manifest->get($cover) ?? $manifest->findByHref($cover);
            if ($item instanceof ManifestItem) {
                return $item;
            }
        }

        $guidePath = $manifest->getGuidePath('cover');
        $item = $guidePath === null ? null : $manifest->findByPath($guidePath);
        if (! $item instanceof ManifestItem) {
            return null;
        }

        return str_starts_with($item->mediaType, 'image/') ? $item : $this->firstImageOf($item);
    }

    /**
     * Stores an image and marks it as the cover (EPUB 3 "cover-image" property and
     * EPUB 2 <meta name="cover">).
     *
     * JPEG, PNG, GIF and WebP data is checked against the media type; other formats (such as SVG)
     * are stored as declared.
     *
     * @param string $imageData The image bytes.
     * @param string $mediaType The image media type, e.g. "image/jpeg".
     * @param string|null $path Path relative to the book root; defaults to "images/cover.<ext>" next to the OPF.
     * @param bool $deletePrevious Delete the previous cover image from the book (by default it is kept).
     *
     * @throws Exception If the media type is not an image or does not match the data, or a file cannot be written.
     */
    public function setCoverImage(string $imageData, string $mediaType, ?string $path = null, bool $deletePrevious = false): ManifestItem
    {
        if (! str_starts_with($mediaType, 'image/')) {
            throw new Exception("Cover must be an image, got: {$mediaType}");
        }

        $this->assertImageData($imageData, $mediaType);

        $manifest = $this->getManifest();
        $metadata = $this->getMetadata();
        $previous = $deletePrevious ? $this->getCoverImage() : null;

        if ($path === null) {
            $opfDirectory = dirname($manifest->getOpfPath());
            $path = ($opfDirectory === '.' ? '' : $opfDirectory . '/') . 'images/cover.' . (self::IMAGE_EXTENSIONS[$mediaType] ?? 'img');
        }

        foreach ($manifest->getItems() as $item) {
            $manifest->removeProperty($item->id, self::COVER_PROPERTY);
        }

        $cover = $manifest->findByPath($path) ?? $manifest->add($path, $mediaType);
        $this->getContentManager()->addContent($path, $imageData);
        // The path may have held another format before.
        $manifest->setMediaType($cover->id, $mediaType);

        if (str_starts_with($metadata->getVersion(), '3')) {
            $manifest->addProperty($cover->id, self::COVER_PROPERTY);
        }

        // EPUB 2 readers (and many EPUB 3 ones) look for this meta.
        $metadata->setMeta('cover', $cover->id);

        if ($previous instanceof ManifestItem && $previous->id !== $cover->id) {
            $this->deleteItem($previous);
        }

        return $manifest->get($cover->id) ?? $cover;
    }

    /**
     * Unmarks the cover: removes the EPUB 3 "cover-image" property, the EPUB 2 <meta name="cover">
     * and <guide> cover references. A cover page in the reading order stays.
     *
     * @param bool $deleteFile Also delete the cover image from the book (by default it is kept).
     *
     * @throws Exception If the book is not loaded or the image cannot be deleted.
     */
    public function removeCoverImage(bool $deleteFile = false): void
    {
        $manifest = $this->getManifest();
        $metadata = $this->getMetadata();
        $cover = $this->getCoverImage();

        foreach ($manifest->getItems() as $item) {
            $manifest->removeProperty($item->id, self::COVER_PROPERTY);
        }

        if ($metadata->getMeta('cover') !== null) {
            $metadata->setMeta('cover', null);
        }

        $manifest->removeGuideReferences('cover');

        if ($deleteFile && $cover instanceof ManifestItem) {
            $this->deleteItem($cover);
        }
    }

    /**
     * Deletes an item's file with its manifest item and the references to it; an item whose
     * file is missing is only removed from the manifest.
     */
    private function deleteItem(ManifestItem $item): void
    {
        if (in_array($item->path, $this->getContentManager()->getContentPaths(), true)) {
            $this->getContentManager()->deleteContent($item->path);
        } else {
            $this->getManifest()->remove($item->id);
        }
    }

    /**
     * @throws Exception If image data of a detectable format (JPEG, PNG, GIF, WebP) does not match the media type.
     */
    private function assertImageData(string $imageData, string $mediaType): void
    {
        $size = $imageData === '' ? false : @getimagesizefromstring($imageData);
        $detected = $size === false ? null : $size['mime'];
        $detectable = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (in_array($detected, $detectable, true) && $detected !== $mediaType) {
            throw new Exception("The cover data is {$detected}, not {$mediaType}");
        }

        if (in_array($mediaType, $detectable, true) && $detected !== $mediaType) {
            throw new Exception("The cover data is not a valid {$mediaType} image");
        }
    }

    /**
     * The manifest item of the first image (<img src>, or SVG <image href>) in an XHTML page.
     */
    private function firstImageOf(ManifestItem $page): ?ManifestItem
    {
        if (! in_array($page->mediaType, ['application/xhtml+xml', 'text/html'], true)) {
            return null;
        }

        $document = new DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $this->getContentManager()->getContent($page->path), LIBXML_NONET);
        } catch (Exception) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        $sources = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $sources[] = $image->getAttribute('src');
        }
        foreach ($document->getElementsByTagName('image') as $image) {
            $sources[] = $image->getAttribute('xlink:href') ?: $image->getAttribute('href');
        }

        $manifest = $this->getManifest();
        $directory = dirname($page->path) === '.' ? '' : dirname($page->path) . '/';
        foreach ($sources as $source) {
            try {
                $item = $manifest->findByPath($directory . rawurldecode(explode('#', $source, 2)[0]));
            } catch (InvalidEpubException) {
                continue;
            }

            if ($item instanceof ManifestItem && str_starts_with($item->mediaType, 'image/')) {
                return $item;
            }
        }

        return null;
    }

    /**
     * cleanup() for callers that cannot report a failure; the book is unloaded either way.
     */
    private function cleanupQuietly(): void
    {
        try {
            $this->cleanup();
        } catch (Exception) {
            // Nothing to report to; the directory is left in the system temp dir.
            $this->tempDir = null;
        }
    }

    public function getTempDir(): ?string
    {
        return $this->tempDir;
    }

    /**
     * Writes pending package edits (metadata, manifest, spine) to the OPF file.
     */
    private function writePackage(): void
    {
        if ($this->manifest?->isModified() === true || $this->spine?->isModified() === true) {
            $this->metadata?->markModified();
        }

        $metadata = $this->metadata;
        if ($metadata?->isModified() === true) {
            $metadata->save();
            $this->manifest?->markSaved();
            $this->spine?->markSaved();

            $this->rekeyObfuscatedFonts();

            // The NCX repeats the title (for EPUB 2 reading systems) and the unique identifier.
            $this->getTableOfContents()->syncNcx($metadata->getTitle(), $metadata->getUniqueIdentifier());
        }
    }

    /**
     * Obfuscated fonts are keyed with the unique identifier: when it changed, re-key them so
     * reading systems can still decode them.
     *
     * @throws Exception See FontObfuscation::rekey().
     */
    private function rekeyObfuscatedFonts(): void
    {
        $identifier = $this->metadata?->getUniqueIdentifier();
        if ($this->tempDir === null || $identifier === null || $this->fontKeyIdentifier === null || $identifier === $this->fontKeyIdentifier) {
            return;
        }

        (new FontObfuscation($this->tempDir, $this->xmlParser))->rekey($this->fontKeyIdentifier, $identifier);
        $this->fontKeyIdentifier = $identifier;
    }

    public function getMetadata(): Metadata
    {
        if ($this->metadata === null) {
            throw new Exception('EPUB file must be loaded before accessing metadata.');
        }

        return $this->metadata;
    }

    public function getSpine(): Spine
    {
        if ($this->spine === null) {
            throw new Exception('EPUB file must be loaded before accessing spine.');
        }

        return $this->spine;
    }

    public function getManifest(): Manifest
    {
        if ($this->manifest === null) {
            throw new Exception('EPUB file must be loaded before accessing manifest.');
        }

        return $this->manifest;
    }

    /**
     * Checks the book for common structural problems (required metadata, manifest and spine
     * consistency, navigation), including unsaved changes. A quick check, not a replacement for EPUBCheck.
     *
     * @return list<ValidationIssue> Empty when no problem was found.
     *
     * @throws Exception If the book is not loaded or its navigation cannot be parsed.
     */
    public function validate(): array
    {
        if ($this->tempDir === null || $this->opfXml === null || $this->metadata === null || $this->manifest === null || $this->spine === null) {
            throw new Exception('EPUB file must be loaded before validating.');
        }

        return (new Validator($this->tempDir, $this->opfXml, $this->metadata, $this->manifest, $this->spine, $this->getTableOfContents()))->validate();
    }

    /**
     * The table of contents (EPUB 3 navigation document and EPUB 2 NCX). Changes are written
     * to those files immediately and saved with the book.
     */
    public function getTableOfContents(): TableOfContents
    {
        if ($this->tempDir === null || $this->manifest === null) {
            throw new Exception('EPUB file must be loaded before accessing the table of contents.');
        }

        return new TableOfContents($this->tempDir, $this->manifest, $this->xmlParser, new PathResolver(), $this, $this->spine);
    }

    public function getContentManager(): ContentManager
    {
        if ($this->contentManager === null) {
            throw new Exception('EPUB file must be loaded before accessing content manager.');
        }

        return $this->contentManager;
    }
}
