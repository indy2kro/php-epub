<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithLanguage
{
    /**
     * Gets the language of the EPUB.
     */
    public function getLanguage(): string
    {
        return (string) $this->opfXml->metadata->language;
    }

    /**
     * Sets the language of the EPUB.
     */
    public function setLanguage(string $language): void
    {
        $this->opfXml->metadata->language = $language;
    }
}
