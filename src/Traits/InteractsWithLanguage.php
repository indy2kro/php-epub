<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use PhpEpub\Exception;
use PhpEpub\Util\MetadataSyntax;
use PhpEpub\Util\XmlText;

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
     *
     * @param string $language A well-formed BCP 47 tag, e.g. "en" or "pt-BR".
     *
     * @throws Exception If the language is empty or not a well-formed BCP 47 tag.
     */
    public function setLanguage(string $language): void
    {
        $this->assertLanguageTags([$language]);
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
     * @param list<string> $languages Well-formed BCP 47 tags.
     *
     * @throws Exception If the list or a language is empty, or a language is not a well-formed BCP 47 tag.
     */
    public function setLanguages(array $languages): void
    {
        $this->assertLanguageTags($languages);
        $this->setDcValues('language', $languages);
    }

    /**
     * @param list<string> $languages
     *
     * @throws Exception If a language is not a well-formed BCP 47 tag; empty ones are left to the caller's own check.
     */
    private function assertLanguageTags(array $languages): void
    {
        XmlText::assertValid(...$languages);

        foreach ($languages as $language) {
            if (trim($language) !== '' && ! MetadataSyntax::isLanguageTag($language)) {
                throw new Exception("Not a well-formed BCP 47 language tag: \"{$language}\"");
            }
        }
    }
}
