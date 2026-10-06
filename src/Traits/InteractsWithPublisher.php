<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithPublisher
{
    /**
     * Gets the publisher of the EPUB (the first dc:publisher).
     */
    public function getPublisher(): string
    {
        return $this->getDcValue('publisher');
    }

    /**
     * Sets the publisher of the EPUB, creating dc:publisher when missing.
     */
    public function setPublisher(string $publisher): void
    {
        $this->setDcValue('publisher', $publisher);
    }
}
