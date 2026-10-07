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
     * Bookmarks added to the last PDF built by createPdfFor(), as [title, level].
     *
     * @var list<array{string, int}>
     */
    public array $bookmarks = [];

    protected function newPdf(string $orientation, string $format): TCPDF
    {
        $this->bookmarks = [];
        $record = function (string $title, int $level): void {
            $this->bookmarks[] = [$title, $level];
        };

        return new class ($orientation, 'mm', $format, $record) extends TCPDF {
            /**
             * @param \Closure(string, int): void $record
             */
            public function __construct(string $orientation, string $unit, string $format, private readonly \Closure $record)
            {
                parent::__construct($orientation, $unit, $format);
            }

            /**
             * Records each bookmark before TCPDF stores it.
             *
             * @param string $_txt
             * @param int $_level
             * @param float|int $_y
             * @param int|string $_page
             * @param string $_style
             * @param array<int, int> $_color
             * @param float|int $_x
             * @param mixed $_link
             *
             * @return mixed
             */
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides TCPDF's own method name
            public function Bookmark($_txt, $_level = 0, $_y = -1, $_page = '', $_style = '', $_color = [0, 0, 0], $_x = -1, $_link = '')
            {
                ($this->record)($_txt, $_level);

                return parent::Bookmark($_txt, $_level, $_y, $_page, $_style, $_color, $_x, $_link);
            }
        };
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
