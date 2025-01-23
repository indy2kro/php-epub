<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithCreator
{
    /**
     * Gets the creator of the EPUB.
     */
    public function getCreator(): string
    {
        return (string) $this->opfXml->metadata->creator;
    }

    /**
     * Sets the creator of the EPUB.
     */
    public function setCreator(string $creator): void
    {
        $this->opfXml->metadata->creator = $creator;
    }
}
