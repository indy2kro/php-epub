<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithTitle
{
    /**
     * Gets the title of the EPUB (the first dc:title).
     */
    public function getTitle(): string
    {
        return $this->getDcValue('title');
    }

    /**
     * Sets the title of the EPUB, creating dc:title when missing.
     */
    public function setTitle(string $title): void
    {
        $this->setDcValue('title', $title);
    }
}
