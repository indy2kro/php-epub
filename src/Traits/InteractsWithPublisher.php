<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithPublisher
{
    /**
     * Gets the publisher of the EPUB.
     */
    public function getPublisher(): string
    {
        return (string) $this->opfXml->metadata->publisher;
    }

    /**
     * Sets the publisher of the EPUB.
     */
    public function setPublisher(string $publisher): void
    {
        $this->opfXml->metadata->publisher = $publisher;
    }
}
