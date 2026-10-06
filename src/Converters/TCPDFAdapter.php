<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\ConversionException;
use TCPDF;

class TCPDFAdapter implements ConverterInterface
{
    private const array DEFAULT_STYLES = [
        'font' => 'helvetica',
        'font_size' => 12,
        'margin_left' => 15,
        'margin_top' => 27,
        'margin_right' => 15,
        'margin_bottom' => 25,
        'header' => true,
        'footer' => true,
    ];

    /**
     * @var array<string, mixed>
     */
    private array $styles;

    /**
     * TCPDFAdapter constructor.
     *
     * @param array<string, mixed> $styles Optional styling parameters: font, font_size, margin_left,
     *                                     margin_top, margin_right, margin_bottom (int), header, footer (bool).
     *                                     Values of the wrong type fall back to the defaults.
     */
    public function __construct(array $styles = [], private readonly EpubDocumentLoader $loader = new EpubDocumentLoader())
    {
        $this->styles = array_merge(self::DEFAULT_STYLES, $styles);
    }

    /**
     * Converts the EPUB content to a PDF using TCPDF.
     *
     * Every spine document is rendered in reading order, each starting on a new page,
     * and the PDF title and author are taken from the EPUB metadata.
     *
     * @param string $epubDirectory The directory containing the extracted EPUB contents.
     * @param string $outputPath The path where the converted PDF should be saved.
     *
     * @throws ConversionException If the conversion fails.
     */
    public function convert(string $epubDirectory, string $outputPath): void
    {
        // TCPDF resolves K_PATH_FONTS itself (through Composer), whether php-epub is the root
        // project or a dependency; defining it here would point at the wrong vendor directory.
        $pdf = $this->createPdf($this->loader->load($epubDirectory));

        if (@file_put_contents($outputPath, $pdf->Output('', 'S')) === false) {
            throw new ConversionException("Failed to write PDF: {$outputPath}");
        }
    }

    /**
     * Builds the PDF document in memory.
     */
    protected function createPdf(EpubDocument $document): TCPDF
    {
        $author = implode(', ', $document->authors);

        $pdf = new TCPDF();
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetTitle($document->title);
        $pdf->SetAuthor($author);

        if ($this->boolStyle('header')) {
            $pdf->setHeaderData('', 0, $document->title, $author);
        } else {
            $pdf->setPrintHeader(false);
        }

        if ($this->boolStyle('footer')) {
            $pdf->setFooterData();
        } else {
            $pdf->setPrintFooter(false);
        }

        $pdf->SetMargins($this->intStyle('margin_left'), $this->intStyle('margin_top'), $this->intStyle('margin_right'));
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $pdf->SetAutoPageBreak(true, $this->intStyle('margin_bottom'));
        $pdf->SetFont($this->stringStyle('font'), '', $this->intStyle('font_size'));

        foreach ($document->chapters as $chapter) {
            $pdf->AddPage();
            $pdf->writeHTML($chapter, true, false, true, false, '');
        }

        if ($document->chapters === []) {
            $pdf->AddPage();
        }

        return $pdf;
    }

    private function stringStyle(string $name): string
    {
        $value = $this->styles[$name] ?? null;

        return is_string($value) ? $value : (string) self::DEFAULT_STYLES[$name];
    }

    private function intStyle(string $name): int
    {
        $value = $this->styles[$name] ?? null;

        return is_int($value) ? $value : (int) self::DEFAULT_STYLES[$name];
    }

    private function boolStyle(string $name): bool
    {
        $value = $this->styles[$name] ?? null;

        return is_bool($value) ? $value : (bool) self::DEFAULT_STYLES[$name];
    }
}
