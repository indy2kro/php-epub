<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithPublisher
{
    /**
     * Gets the publisher of the EPUB.
     */
    public function getPublisher(): string
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $publisherNode = $this->opfXml->xpath('//dc:publisher');

        if ($publisherNode === false || $publisherNode === null || $publisherNode === []) {
            return '';
        }

        return (string) $publisherNode[0];
    }

    /**
     * Sets the publisher of the EPUB.
     */
    public function setPublisher(string $publisher): void
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $publisherNode = $this->opfXml->xpath('//dc:publisher');

        if ($publisherNode !== false && $publisherNode !== null && $publisherNode !== [] && isset($publisherNode[0])) {
            // @phpstan-ignore-next-line
            $publisherNode[0][0] = $publisher;
        } else {
            $this->opfXml->metadata->addChild('publisher', $publisher, $this->dcNamespace);
        }
    }
}
