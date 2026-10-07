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

    /**
     * Gets every language of the EPUB (dc:language), the main one first.
     *
     * @return list<string>
     */
    public function getLanguages(): array
    {
        return $this->getDcValues('language');
    }

    /**
     * Replaces the languages of the EPUB; the first is the main one.
     *
     * @param list<string> $languages
     *
     * @throws \PhpEpub\Exception If the list or a language is empty.
     */
    public function setLanguages(array $languages): void
    {
        $this->setDcValues('language', $languages);
    }
}
