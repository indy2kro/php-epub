<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\FileSystemHelper;
use SimpleXMLElement;

class EpubFile
{
    private ?string $tempDir = null;
    private readonly XmlParser $xmlParser;
    private readonly ZipHandler $zipHandler;
    private readonly Parser $parser;
    private ?Metadata $metadata = null;
    private ?Spine $spine = null;
    private ?SimpleXMLElement $opfXml = null;

    public function __construct(private readonly string $filePath)
    {
        $this->zipHandler = new ZipHandler();
        $this->xmlParser = new XmlParser();
        $this->parser = new Parser($this->xmlParser);
    }

    public function __destruct()
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $helper = new FileSystemHelper();
            $helper->deleteDirectory($this->tempDir);
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

    public function getMetadata(): ?Metadata
    {
        return $this->metadata;
    }

    public function getSpine(): ?Spine
    {
        return $this->spine;
    }
}
