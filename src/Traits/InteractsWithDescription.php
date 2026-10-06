<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithDescription
{
    /**
     * Gets the description of the EPUB (the first dc:description).
     */
    public function getDescription(): string
    {
        return $this->getDcValue('description');
    }

    /**
     * Sets the description of the EPUB, creating dc:description when missing.
     */
    public function setDescription(string $description): void
    {
        $this->setDcValue('description', $description);
    }
}
