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

    /**
     * @return list<string>
     */
    public function chapterHtmlFor(string $epubDirectory): array
    {
        $document = (new EpubDocumentLoader())->load($epubDirectory);

        return array_map(fn (string $chapter): string => $this->chapterHtml($document, $chapter), $document->chapters);
    }
}
