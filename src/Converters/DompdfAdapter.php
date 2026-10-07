<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpEpub\ConversionException;

class DompdfAdapter implements ConverterInterface
{
    private const array DEFAULT_STYLES = [
        'font' => 'Arial',
        'font_size' => 12,
        'paper_size' => 'A4',
        'orientation' => 'portrait',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $styles;

    /**
     * DompdfAdapter constructor.
     *
     * @param array<string, mixed> $styles Optional styling parameters: font, font_size (pt), paper_size,
     *                                     orientation, and margin_top/right/bottom/left (int, mm, as in TCPDFAdapter;
     *                                     without any, Dompdf keeps its own margins).
     */
    public function __construct(array $styles = [], private readonly EpubDocumentLoader $loader = new EpubDocumentLoader())
    {
        $this->styles = array_merge(self::DEFAULT_STYLES, $styles);
    }

    /**
     * Converts the EPUB content to a PDF using Dompdf.
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
        $document = $this->loader->load($epubDirectory);

        $dompdf = $this->createDompdf($epubDirectory);
        $dompdf->loadHtml($this->renderHtml($document));
        $dompdf->setPaper($this->stringStyle('paper_size'), $this->stringStyle('orientation'));

        if ($document->title !== '') {
            $dompdf->addInfo('Title', $document->title);
        }

        if ($document->authors !== []) {
            $dompdf->addInfo('Author', implode(', ', $document->authors));
        }

        $dompdf->render();

        if (@file_put_contents($outputPath, (string) $dompdf->output()) === false) {
            throw new ConversionException("Failed to write PDF: {$outputPath}");
        }
    }

    /**
     * Returns the HTML document that convert() renders, e.g. for previews.
     *
     * @throws ConversionException If the book cannot be read.
     */
    public function buildHtml(string $epubDirectory): string
    {
        return $this->renderHtml($this->loader->load($epubDirectory));
    }

    /**
     * Creates a Dompdf instance that cannot reach outside the book: no remote
     * resources, no embedded PHP or JavaScript, and file access limited to the book.
     */
    protected function createDompdf(string $epubDirectory): Dompdf
    {
        $root = realpath($epubDirectory);

        $options = new Options();
        $options->set('defaultFont', $this->stringStyle('font'));
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([$root === false ? $epubDirectory : $root]);

        return new Dompdf($options);
    }

    private function renderHtml(EpubDocument $document): string
    {
        $font = str_replace(['"', '<', '>', ';', '}'], '', $this->stringStyle('font'));
        $css = sprintf('body { font-family: "%s"; font-size: %dpt; }', $font, $this->intStyle('font_size')) . $this->pageMarginCss();

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<title>' . htmlspecialchars($document->title, ENT_QUOTES | ENT_HTML5) . '</title>'
            . '<style>' . $css . '</style>'
            // The book's own CSS comes after the defaults, so the book's styling wins.
            . ($document->styles === [] ? '' : '<style>' . EpubDocument::styleSheet($document->styles) . '</style>')
            . '</head><body>'
            . implode('<div style="page-break-before: always"></div>', $this->pages($document))
            . '</body></html>';
    }

    /**
     * The chapters, after a cover page when the book has a cover no chapter shows.
     *
     * @return list<string>
     */
    private function pages(EpubDocument $document): array
    {
        if ($document->coverImage === '') {
            return $document->chapters;
        }

        // Scaled down to fit the page, keeping its proportions.
        $cover = '<div style="height: 100%; text-align: center;"><img src="' . htmlspecialchars($document->coverImage)
            . '" alt="" style="max-width: 100%; max-height: 100%;"/></div>';

        return [$cover, ...$document->chapters];
    }

    /**
     * A CSS @page margin rule from margin_top/right/bottom/left (mm, as in TCPDFAdapter);
     * empty when none is given, so Dompdf keeps its own default margins.
     */
    private function pageMarginCss(): string
    {
        $sides = ['margin_top', 'margin_right', 'margin_bottom', 'margin_left'];
        $given = array_filter($sides, fn (string $side): bool => is_int($this->styles[$side] ?? null));
        if ($given === []) {
            return '';
        }

        $margins = array_map(fn (string $side): string => (is_int($this->styles[$side] ?? null) ? $this->styles[$side] : 0) . 'mm', $sides);

        return ' @page { margin: ' . implode(' ', $margins) . '; }';
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
}
