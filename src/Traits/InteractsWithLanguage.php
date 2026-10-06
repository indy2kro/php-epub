<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithLanguage
{
    /**
     * Gets the language of the EPUB (the first dc:language).
     */
    public function getLanguage(): string
    {
        return $this->getDcValue('language');
    }

    /**
     * Sets the language of the EPUB, creating dc:language when missing.
     */
    public function setLanguage(string $language): void
    {
        $this->setDcValue('language', $language);
    }
}
