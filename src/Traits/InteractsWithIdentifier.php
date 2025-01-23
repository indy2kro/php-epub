<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithIdentifier
{
    /**
     * Gets the authors of the EPUB.
     *
     * @return array<int, string>
     */
    public function getIdentifiers(): array
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $identifierNodes = $this->opfXml->xpath('//dc:identifier');

        $identifiers = [];

        if ($identifierNodes === false || $identifierNodes === null || $identifierNodes === []) {
            return $identifiers;
        }

        foreach ($identifierNodes as $identifierNode) {
            $identifiers[] = (string) $identifierNode;
        }
        return $identifiers;
    }

    /**
     * Sets the identifiers of the EPUB.
     *
     * @param array<int, string> $identifiers
     */
    public function setIdentifiers(array $identifiers): void
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $identifierNodes = $this->opfXml->xpath('//dc:identifier');

        if ($identifierNodes !== false && $identifierNodes !== null && $identifierNodes !== []) {
            foreach ($identifierNodes as $key => $identifierNode) {
                unset($identifierNodes[$key][0]);
            }
        }

        foreach ($identifiers as $identifier) {
            $this->opfXml->metadata->addChild('identifier', $identifier, $this->dcNamespace);
        }
    }
}
