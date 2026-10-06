<?php

declare(strict_types=1);

namespace PhpEpub\Test\Support;

use Dompdf\Dompdf;
use PhpEpub\Converters\DompdfAdapter;

/**
 * Exposes the configured Dompdf instance so tests can inspect its security options.
 */
final class ExposedDompdfAdapter extends DompdfAdapter
{
    public function createDompdfFor(string $epubDirectory): Dompdf
    {
        return $this->createDompdf($epubDirectory);
    }
}
