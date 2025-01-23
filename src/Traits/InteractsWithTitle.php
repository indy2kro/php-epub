<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use SimpleXMLElement;

trait InteractsWithTitle
{
    private readonly SimpleXMLElement $opfXml;
    private readonly string $dcNamespace;

    /**
     * Gets the title of the EPUB.
     */
    public function getTitle(): string
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $titleNode = $this->opfXml->xpath('//dc:title');

        if ($titleNode === false || $titleNode === null || $titleNode === []) {
            return '';
        }

        return (string) $titleNode[0];
    }

    /**
     * Sets the title of the EPUB.
     */
    public function setTitle(string $title): void
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $titleNode = $this->opfXml->xpath('//dc:title');

        if ($titleNode !== false && $titleNode !== null && $titleNode !== [] && isset($titleNode[0])) {
            // @phpstan-ignore-next-line
            $titleNode[0][0] = $title;
        } else {
            $this->opfXml->metadata->addChild('title', $title, $this->dcNamespace);
        }
    }
}
