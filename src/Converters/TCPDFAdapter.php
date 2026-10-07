<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\ConversionException;
use PhpEpub\Util\FileSystemHelper;
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
        'paper_size' => 'A4',
        'orientation' => 'portrait',
        'bookmarks' => true,
    ];

    /**
     * @var array<string, mixed>
     */
    private array $styles;

    /**
     * TCPDFAdapter constructor.
     *
     * @param array<string, mixed> $styles Optional styling parameters: font, font_size, margin_left,
     *                                     margin_top, margin_right, margin_bottom (int, mm), header, footer (bool),
     *                                     paper_size (e.g. "A4", "letter") and orientation ("portrait" or
     *                                     "landscape"), as in DompdfAdapter, and bookmarks (bool, default true:
     *                                     a PDF outline entry per chapter). Values of the wrong type fall back to the defaults.
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
        // Inlined SVGs are written here for TCPDF (see svgImagesAsFiles()); a private directory,
        // so TCPDF can be allowed to read it without the rest of the system temp dir.
        $svgDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_pdf_' . bin2hex(random_bytes(16));
        @mkdir($svgDirectory, 0700) || throw new ConversionException("Failed to create temporary directory: {$svgDirectory}");

        try {
            return $this->fillPdf($document, array_values(array_filter([$document->directory, $svgDirectory])), $svgDirectory);
        } finally {
            (new FileSystemHelper())->deleteDirectory($svgDirectory);
        }
    }

    /**
     * @param list<string> $readableDirectories
     */
    private function fillPdf(EpubDocument $document, array $readableDirectories, string $svgDirectory): TCPDF
    {
        $author = implode(', ', $document->authors);

        $orientation = strtolower($this->stringStyle('orientation')) === 'landscape' ? 'L' : 'P';
        $pdf = $this->newPdf($orientation, strtoupper($this->stringStyle('paper_size')), $readableDirectories);
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

        if ($document->coverImage !== '') {
            $pdf->AddPage();
            if ($this->boolStyle('bookmarks')) {
                $pdf->Bookmark('Cover', 0, 0);
            }

            // Scaled to fit the area inside the margins, centred.
            $left = $this->intStyle('margin_left');
            $top = $this->intStyle('margin_top');
            $width = $pdf->getPageWidth() - $left - $this->intStyle('margin_right');
            $height = $pdf->getPageHeight() - $top - $this->intStyle('margin_bottom');
            $pdf->Image($document->coverImage, $left, $top, $width, $height, '', '', '', true, 300, '', false, false, 0, 'CM');
        }

        foreach ($document->chapters as $index => $chapter) {
            $pdf->AddPage();
            if ($this->boolStyle('bookmarks')) {
                $title = $document->chapterTitles[$index] ?? '';
                $pdf->Bookmark($title !== '' ? $title : 'Chapter ' . ($index + 1), 0, 0);
            }

            $pdf->writeHTML($this->chapterHtml($document, $this->svgImagesAsFiles($chapter, $svgDirectory)), true, false, true, false, '');
        }

        if ($document->chapters === [] && $document->coverImage === '') {
            $pdf->AddPage();
        }

        return $pdf;
    }

    /**
     * Creates the TCPDF instance (in mm) that createPdf() fills.
     *
     * @param string $orientation "P" or "L".
     * @param string $format A TCPDF page format, e.g. "A4" or "LETTER".
     * @param list<string> $readableDirectories The only directories (besides TCPDF's own files) TCPDF may read.
     */
    protected function newPdf(string $orientation, string $format, array $readableDirectories = []): TCPDF
    {
        return new ConfinedTcpdf($orientation, $format, $readableDirectories);
    }

    /**
     * A chapter as passed to writeHTML(): TCPDF takes CSS from <style> elements in that HTML,
     * so the book's styles are prepended to every chapter.
     */
    protected function chapterHtml(EpubDocument $document, string $chapter): string
    {
        return ($document->styles === [] ? '' : '<style>' . EpubDocument::styleSheet($document->styles) . '</style>') . $chapter;
    }

    /**
     * TCPDF only renders an <img> as SVG when its source ends in ".svg", so the sanitised SVG
     * data: URIs of EpubDocumentLoader are written to temporary files for the conversion.
     *
     * @param string $directory The private directory createPdf() deletes afterwards.
     */
    private function svgImagesAsFiles(string $html, string $directory): string
    {
        return (string) preg_replace_callback(
            '#\bsrc="data:image/svg\+xml;base64,([A-Za-z0-9+/=]+)"#',
            static function (array $match) use ($directory): string {
                $file = $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.svg';

                return @file_put_contents($file, (string) base64_decode($match[1], true)) !== false
                    ? 'src="' . htmlspecialchars(str_replace('\\', '/', $file)) . '"'
                    : $match[0];
            },
            $html
        );
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
