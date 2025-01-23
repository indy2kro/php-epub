<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithTitle
{
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
}
