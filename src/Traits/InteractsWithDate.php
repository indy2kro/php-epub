<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithDate
{
    /**
     * Gets the date of the EPUB (the first dc:date).
     */
    public function getDate(): string
    {
        return $this->getDcValue('date');
    }

    /**
     * Sets the date of the EPUB, creating dc:date when missing.
     */
    public function setDate(string $date): void
    {
        $this->setDcValue('date', $date);
    }
}
