<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use SimpleXMLElement;

trait InteractsWithAuthors
{
    protected readonly SimpleXMLElement $opfXml;

    /**
     * Gets the authors of the EPUB.
     *
     * @return array<int, string>
     */
    public function getAuthors(): array
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $creatorNodes = $this->opfXml->xpath('//dc:creator');

        if ($this->isAuthorsNodeEmpty($creatorNodes)) {
            return [];
        }

        $authors = [];
        foreach ($creatorNodes as $creatorNode) {
            $authors[] = (string) $creatorNode;
        }
        return $authors;
    }

    /**
     * Sets the authors of the EPUB.
     *
     * @param array<int, string> $authors
     */
    public function setAuthors(array $authors): void
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $creatorNodes = $this->opfXml->xpath('//dc:creator');

        if (! $this->isAuthorsNodeEmpty($creatorNodes)) {
            foreach ($creatorNodes as $key => $creatorNode) {
                unset($creatorNodes[$key][0]);
            }
        }

        foreach ($authors as $author) {
            $this->opfXml->metadata->addChild('creator', $author, $this->dcNamespace);
        }
    }

    /**
     * @param array<SimpleXMLElement>|false|null $creatorNodes
     *
     * @phpstan-assert-if-false array<SimpleXMLElement> $creatorNodes
     */
    private function isAuthorsNodeEmpty(mixed $creatorNodes): bool
    {
        if ($creatorNodes === false || $creatorNodes === null || $creatorNodes === []) {
            return true;
        }

        return false;
    }
}
