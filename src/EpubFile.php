<?php

declare(strict_types=1);

namespace PhpEpub;

use DOMDocument;
use PhpEpub\Converters\ConverterInterface;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\PathResolver;
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
    private readonly ZipHandler $zipHandler;
    private readonly XmlParser $xmlParser;
    private readonly Parser $parser;
    private ?Metadata $metadata = null;
    private ?Spine $spine = null;
    private ?Manifest $manifest = null;
    private ?SimpleXMLElement $opfXml = null;
    private ?ContentManager $contentManager = null;

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

    public function load(): void
    {
        // Loading again starts from the file on disk; drop the previous extraction
        // (if it cannot be deleted, it must not stop the new book from loading).
        $this->cleanupQuietly();

        // Unpredictable name and owner-only permissions: the extracted book may be private.
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_' . bin2hex(random_bytes(16));
        if (! mkdir($this->tempDir, 0700)) {
            throw new Exception("Failed to create temporary directory: {$this->tempDir}");
        }

        try {
            $this->zipHandler->extract($this->filePath, $this->tempDir);

            $opfFilePath = $this->parser->parse($this->tempDir);
            $opfFileFullPath = $this->tempDir . DIRECTORY_SEPARATOR . $opfFilePath;

            $this->opfXml = $this->xmlParser->parse($opfFileFullPath);

            $this->metadata = new Metadata($this->opfXml, $opfFileFullPath);
            $this->manifest = new Manifest($this->opfXml, $opfFilePath);
            $this->spine = new Spine($this->opfXml, $this->manifest);
            $this->contentManager = new ContentManager($this->tempDir, $this->manifest, $this->spine);
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
            $filePath = $this->filePath;
        }

        $this->writePackage();
        $this->zipHandler->compress($tempDir, $filePath);
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
     * EPUB 2 <meta name="cover">). The previous cover image file is kept in the book.
     *
     * @param string $imageData The image bytes.
     * @param string $mediaType The image media type, e.g. "image/jpeg".
     * @param string|null $path Path relative to the book root; defaults to "images/cover.<ext>" next to the OPF.
     *
     * @throws Exception If the media type is not an image or the file cannot be written.
     */
    public function setCoverImage(string $imageData, string $mediaType, ?string $path = null): ManifestItem
    {
        if (! str_starts_with($mediaType, 'image/')) {
            throw new Exception("Cover must be an image, got: {$mediaType}");
        }

        $manifest = $this->getManifest();
        $metadata = $this->getMetadata();

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

        return $manifest->get($cover->id) ?? $cover;
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

        if ($this->metadata?->isModified() === true) {
            $this->metadata->save();
            $this->manifest?->markSaved();
            $this->spine?->markSaved();
        }
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
     * The table of contents (EPUB 3 navigation document and EPUB 2 NCX). Changes are written
     * to those files immediately and saved with the book.
     */
    public function getTableOfContents(): TableOfContents
    {
        if ($this->tempDir === null || $this->manifest === null) {
            throw new Exception('EPUB file must be loaded before accessing the table of contents.');
        }

        return new TableOfContents($this->tempDir, $this->manifest, $this->xmlParser, new PathResolver(), $this);
    }

    public function getContentManager(): ContentManager
    {
        if ($this->contentManager === null) {
            throw new Exception('EPUB file must be loaded before accessing content manager.');
        }

        return $this->contentManager;
    }
}
