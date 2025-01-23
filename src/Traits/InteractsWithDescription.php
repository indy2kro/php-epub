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
        return (string) $this->opfXml->metadata->description;
    }

    /**
     * Sets the description of the EPUB.
     */
    public function setDescription(string $description): void
    {
        $this->opfXml->metadata->description = $description;
    }
}
