<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Converters\ConverterInterface;
use PhpEpub\Util\FileSystemHelper;
use SimpleXMLElement;

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
        $this->cleanup();
    }

    public function cleanup(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $helper = new FileSystemHelper();
            $helper->deleteDirectory($this->tempDir);
            $this->tempDir = null;
        }
    }

    public function load(): void
    {
        // Loading again starts from the file on disk; drop the previous extraction.
        $this->cleanup();

        // Unpredictable name and owner-only permissions: the extracted book may be private.
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_' . bin2hex(random_bytes(16));
        if (! mkdir($this->tempDir, 0700)) {
            throw new Exception("Failed to create temporary directory: {$this->tempDir}");
        }

        $this->zipHandler->extract($this->filePath, $this->tempDir);

        $opfFilePath = $this->parser->parse($this->tempDir);
        $opfFileFullPath = $this->tempDir . DIRECTORY_SEPARATOR . $opfFilePath;

        $this->opfXml = $this->xmlParser->parse($opfFileFullPath);

        $this->metadata = new Metadata($this->opfXml, $opfFileFullPath);
        $this->manifest = new Manifest($this->opfXml, $opfFilePath);
        $this->spine = new Spine($this->opfXml, $this->manifest);
        $this->contentManager = new ContentManager($this->tempDir, $this->manifest, $this->spine);
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
     * Returns the cover image: the manifest item with the EPUB 3 "cover-image"
     * property, or else the item named by the EPUB 2 <meta name="cover">.
     */
    public function getCoverImage(): ?ManifestItem
    {
        $manifest = $this->getManifest();

        foreach ($manifest->getItems() as $item) {
            if (in_array(self::COVER_PROPERTY, explode(' ', $item->properties), true)) {
                return $item;
            }
        }

        $coverId = $this->getMetadata()->getMeta('cover');

        return $coverId === null ? null : $manifest->get($coverId);
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

        if (str_starts_with($metadata->getVersion(), '3')) {
            $manifest->addProperty($cover->id, self::COVER_PROPERTY);
        }

        // EPUB 2 readers (and many EPUB 3 ones) look for this meta.
        $metadata->setMeta('cover', $cover->id);

        return $manifest->get($cover->id) ?? $cover;
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

    public function getContentManager(): ContentManager
    {
        if ($this->contentManager === null) {
            throw new Exception('EPUB file must be loaded before accessing content manager.');
        }

        return $this->contentManager;
    }
}
