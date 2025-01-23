<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithAuthors
{
    /**
     * Gets the authors of the EPUB.
     *
     * @return array<int, string>
     */
    public function getAuthors(): array
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $creatorNodes = $this->opfXml->xpath('//dc:creator');

        $authors = [];

        if ($creatorNodes === false || $creatorNodes === null || $creatorNodes === []) {
            return $authors;
        }

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

        if ($creatorNodes !== false && $creatorNodes !== null && $creatorNodes !== []) {
            foreach ($creatorNodes as $key => $creatorNode) {
                unset($creatorNodes[$key][0]);
            }
        }

        foreach ($authors as $author) {
            $this->opfXml->metadata->addChild('creator', $author, $this->dcNamespace);
        }
    }
}
