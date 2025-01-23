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
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $languageNode = $this->opfXml->xpath('//dc:language');

        if ($languageNode === false || $languageNode === null || $languageNode === []) {
            return '';
        }

        return (string) $languageNode[0];
    }

    /**
     * Sets the language of the EPUB.
     */
    public function setLanguage(string $language): void
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $languageNode = $this->opfXml->xpath('//dc:language');

        if ($languageNode !== false && $languageNode !== null && $languageNode !== [] && isset($languageNode[0])) {
            // @phpstan-ignore-next-line
            $languageNode[0][0] = $language;
        } else {
            $this->opfXml->metadata->addChild('language', $language, $this->dcNamespace);
        }
    }
}
