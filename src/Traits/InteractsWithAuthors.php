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
        $authors = [];
        foreach ($this->opfXml->metadata->creator as $creator) {
            $authors[] = (string) $creator;
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
        unset($this->opfXml->metadata->creator);
        foreach ($authors as $author) {
            $this->opfXml->metadata->addChild('creator', $author);
        }
    }
}
