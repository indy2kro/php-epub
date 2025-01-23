<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use DateTime;

trait InteractsWithDate
{
    /**
     * Gets the date of the EPUB.
     */
    public function getDate(): ?DateTime
    {
        if (! isset($this->opfXml->metadata->date)) {
            return null;
        }

        return new DateTime((string) $this->opfXml->metadata->date);
    }

    /**
     * Sets the date of the EPUB.
     */
    public function setDate(DateTime $date): void
    {
        $this->opfXml->metadata->date = $date->format('Y-m-d');
    }
}
