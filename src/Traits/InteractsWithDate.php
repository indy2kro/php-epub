<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithDate
{
    /**
     * Gets the date of the EPUB.
     */
    public function getDate(): string
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $dateNode = $this->opfXml->xpath('//dc:date');

        if ($dateNode === false || $dateNode === null || $dateNode === []) {
            return '';
        }

        return (string) $dateNode[0];
    }

    /**
     * Sets the date of the EPUB.
     */
    public function setDate(string $date): void
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $dateNode = $this->opfXml->xpath('//dc:date');

        if ($dateNode !== false && $dateNode !== null && $dateNode !== [] && isset($dateNode[0])) {
            // @phpstan-ignore-next-line
            $dateNode[0][0] = $date;
        } else {
            $this->opfXml->metadata->addChild('date', $date, $this->dcNamespace);
        }
    }
}
