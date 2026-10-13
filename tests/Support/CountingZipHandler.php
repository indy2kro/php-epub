<?php

declare(strict_types=1);

namespace PhpEpub\Test\Support;

use PhpEpub\ZipHandler;

/**
 * Counts the archives it extracts and writes, so tests can check how often a book is unpacked and packed.
 */
final class CountingZipHandler extends ZipHandler
{
    public int $extracted = 0;

    public int $compressed = 0;

    public function extract(string $zipFilePath, string $destination): void
    {
        ++$this->extracted;
        parent::extract($zipFilePath, $destination);
    }

    public function compress(string $source, string $zipFilePath): void
    {
        ++$this->compressed;
        parent::compress($source, $zipFilePath);
    }
}
