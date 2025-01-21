<?php

declare(strict_types=1);

namespace PhpEpub;

use SimpleXMLElement;

class Metadata
{
    private readonly SimpleXMLElement $opfXml;

    /**
     * Metadata constructor.
     *
     * @param string $opfFilePath The path to the OPF file.
     *
     * @throws Exception If the OPF file cannot be loaded.
     */
    public function __construct(string $opfFilePath)
    {
        if (! file_exists($opfFilePath)) {
            throw new Exception("OPF file not found: {$opfFilePath}");
        }

        $opfXml = simplexml_load_file($opfFilePath);

        if ($opfXml === false) {
            throw new Exception("Failed to load OPF file: {$opfFilePath}");
        }

        $this->opfXml = $opfXml;
    }

    /**
     * Gets the title of the EPUB.
     */
    public function getTitle(): string
    {
        return (string) $this->opfXml->metadata->title;
    }

    /**
     * Sets the title of the EPUB.
     */
    public function setTitle(string $title): void
    {
        $this->opfXml->metadata->title = $title;
    }

    /**
     * Gets the authors of the EPUB.
     *
     * @return array<string>
     */
    public function getAuthors(): array
    {
        $authors = [];
        foreach ($this->opfXml->metadata->creator as $creator) {
            $authors[] = (string) $creator;
        }
        return $authors;
    }

    /**
     * Sets the authors of the EPUB.
     *
     * @param array<string> $authors
     */
    public function setAuthors(array $authors): void
    {
        unset($this->opfXml->metadata->creator);
        foreach ($authors as $author) {
            $this->opfXml->metadata->addChild('creator', $author);
        }
    }

    /**
     * Gets the publisher of the EPUB.
     */
    public function getPublisher(): string
    {
        return (string) $this->opfXml->metadata->publisher;
    }

    /**
     * Sets the publisher of the EPUB.
     */
    public function setPublisher(string $publisher): void
    {
        $this->opfXml->metadata->publisher = $publisher;
    }

    /**
     * Gets the language of the EPUB.
     */
    public function getLanguage(): string
    {
        return (string) $this->opfXml->metadata->language;
    }

    /**
     * Sets the language of the EPUB.
     */
    public function setLanguage(string $language): void
    {
        $this->opfXml->metadata->language = $language;
    }

    /**
     * Saves the updated OPF file.
     *
     * @param string $opfFilePath The path to save the OPF file.
     *
     * @throws Exception If the OPF file cannot be saved.
     */
    public function save(string $opfFilePath): void
    {
        $result = $this->opfXml->asXML($opfFilePath);
        if ($result === false) {
            throw new Exception("Failed to save OPF file: {$opfFilePath}");
        }
    }
}
