<?php

declare(strict_types=1);

namespace PhpEpub\Test\Support;

use PhpEpub\ZipHandler;

/**
 * Remembers where it was asked to extract, so tests can check the directory is gone afterwards.
 */
final class RecordingZipHandler extends ZipHandler
{
    public ?string $destination = null;

    public function extract(string $zipFilePath, string $destination): void
    {
        $this->destination = $destination;
        parent::extract($zipFilePath, $destination);
    }
}
