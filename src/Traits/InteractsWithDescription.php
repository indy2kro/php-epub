<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithDescription
{
    /**
     * Gets the description of the EPUB.
     */
    public function getDescription(): string
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $descriptionNode = $this->opfXml->xpath('//dc:description');

        if ($descriptionNode === false || $descriptionNode === null || $descriptionNode === []) {
            return '';
        }

        return (string) $descriptionNode[0];
    }

    /**
     * Sets the description of the EPUB.
     */
    public function setDescription(string $description): void
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $descriptionNode = $this->opfXml->xpath('//dc:description');

        if ($descriptionNode !== false && $descriptionNode !== null && $descriptionNode !== [] && isset($descriptionNode[0])) {
            // @phpstan-ignore-next-line
            $descriptionNode[0][0] = $description;
        } else {
            $this->opfXml->metadata->addChild('description', $description, $this->dcNamespace);
        }
    }
}
