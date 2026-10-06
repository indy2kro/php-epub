<?php

declare(strict_types=1);

namespace PhpEpub\Test\Support;

use PhpEpub\Converters\EpubDocumentLoader;
use PhpEpub\Converters\TCPDFAdapter;
use TCPDF;

/**
 * Exposes the configured TCPDF instance so tests can inspect pages, fonts and margins.
 */
final class ExposedTCPDFAdapter extends TCPDFAdapter
{
    public function createPdfFor(string $epubDirectory): TCPDF
    {
        return $this->createPdf((new EpubDocumentLoader())->load($epubDirectory));
    }
}
