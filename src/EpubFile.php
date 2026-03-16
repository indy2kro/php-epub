<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\FileSystemHelper;
use SimpleXMLElement;

class EpubFile
{
    private ?string $tempDir = null;
    private readonly ZipHandler $zipHandler;
    private readonly XmlParser $xmlParser;
    private readonly Parser $parser;
    private ?Metadata $metadata = null;
    private ?Spine $spine = null;
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
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('epub_', true);
        if (! mkdir($this->tempDir) && ! is_dir($this->tempDir)) {
            throw new Exception("Failed to create temporary directory: {$this->tempDir}");
        }

        $this->zipHandler->extract($this->filePath, $this->tempDir);

        $opfFilePath = $this->parser->parse($this->tempDir);
        $opfFileFullPath = $this->tempDir . DIRECTORY_SEPARATOR . $opfFilePath;

        $this->opfXml = $this->xmlParser->parse($opfFileFullPath);

        $this->metadata = new Metadata($this->opfXml, $opfFileFullPath);
        $this->spine = new Spine($this->opfXml);
        $this->contentManager = new ContentManager($this->tempDir);
    }

    public function save(?string $filePath = null): void
    {
        if ($this->tempDir === null) {
            throw new Exception('EPUB file must be loaded before saving.');
        }

        if ($filePath === null) {
            $filePath = $this->filePath;
        }

        $this->zipHandler->compress($this->tempDir, $filePath);
    }

    public function getTempDir(): ?string
    {
        return $this->tempDir;
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

    public function getContentManager(): ContentManager
    {
        if ($this->contentManager === null) {
            throw new Exception('EPUB file must be loaded before accessing content manager.');
        }

        return $this->contentManager;
    }
}
