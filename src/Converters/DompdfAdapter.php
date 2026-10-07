<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpEpub\ConversionException;
use PhpEpub\Exception;
use PhpEpub\Util\FileSystemHelper;
use Throwable;

class DompdfAdapter implements ConverterInterface
{
    private const array STYLE_TYPES = [
        'font' => 'string',
        'font_size' => 'number',
        'paper_size' => 'string',
        'orientation' => 'string',
        'margin_top' => 'number',
        'margin_right' => 'number',
        'margin_bottom' => 'number',
        'margin_left' => 'number',
    ];

    /**
     * DejaVu Sans ships with Dompdf and covers Latin, Greek, Cyrillic, Hebrew and Arabic, unlike
     * the PDF core fonts (Arial maps to Helvetica), which are Latin-1 only.
     */
    private const array DEFAULT_STYLES = [
        'font' => 'DejaVu Sans',
        'font_size' => 12,
        'paper_size' => 'A4',
        'orientation' => 'portrait',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $styles;

    /**
     * The private font directory of the conversion in progress (see convert()).
     */
    private ?string $fontDirectory = null;

    /**
     * DompdfAdapter constructor.
     *
     * @param array<string, mixed> $styles Optional styling parameters: font (default "DejaVu Sans"), font_size (pt),
     *                                     paper_size, orientation, and margin_top/right/bottom/left (int or float,
     *                                     mm, as in TCPDFAdapter; without any, Dompdf keeps its own margins).
     *
     * @throws Exception If a style is unknown, of the wrong type or has an unusable value (such as a paper size Dompdf does not know).
     */
    public function __construct(array $styles = [], private readonly EpubDocumentLoader $loader = new EpubDocumentLoader())
    {
        PdfStyles::validate(
            'DompdfAdapter',
            self::STYLE_TYPES,
            $styles,
            static fn (string $size): bool => isset(CPDF::$PAPER_SIZES[strtolower($size)])
        );

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
     * @throws ConversionException If the book cannot be read, Dompdf fails or the PDF cannot be written.
     */
    public function convert(string $epubDirectory, string $outputPath): void
    {
        $document = $this->loader->load($epubDirectory);

        // Dompdf stores the fonts it loads (the book's @font-face fonts) and a registry of them in its
        // font directory, by default inside vendor/: give every conversion its own, deleted afterwards,
        // so books leave nothing behind and cannot affect each other's fonts.
        $fontDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_fonts_' . bin2hex(random_bytes(16));
        @mkdir($fontDirectory, 0700) || throw new ConversionException("Failed to create temporary directory: {$fontDirectory}");

        try {
            $this->fontDirectory = $fontDirectory;
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
            $pdf = (string) $dompdf->output();
        } catch (Exception $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ConversionException('Dompdf failed to render the book: ' . $exception->getMessage(), 0, $exception);
        } finally {
            $this->fontDirectory = null;
            (new FileSystemHelper())->deleteDirectory($fontDirectory);
        }

        if (@file_put_contents($outputPath, $pdf) === false) {
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
     * During convert(), fonts Dompdf loads are stored in that conversion's private directory.
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
        if ($this->fontDirectory !== null) {
            $options->setFontDir($this->fontDirectory);
            $options->setFontCache($this->fontDirectory);
        }

        return new Dompdf($options);
    }

    private function renderHtml(EpubDocument $document): string
    {
        $font = str_replace(['"', '<', '>', ';', '}'], '', $this->stringStyle('font'));
        $css = sprintf('body { font-family: "%s"; font-size: %spt; }', $font, $this->numberStyle('font_size')) . $this->pageMarginCss();
        $direction = $document->rightToLeft ? ' dir="rtl"' : '';
        $language = $document->language === '' ? '' : ' lang="' . htmlspecialchars($document->language, ENT_QUOTES | ENT_HTML5) . '"';

        return '<!DOCTYPE html><html' . $direction . $language . '><head><meta charset="utf-8">'
            . '<title>' . htmlspecialchars($document->title, ENT_QUOTES | ENT_HTML5) . '</title>'
            . '<style>' . $css . '</style>'
            // The book's own CSS comes after the defaults, so the book's styling wins.
            . ($document->styles === [] ? '' : '<style>' . EpubDocument::styleSheet($document->styles) . '</style>')
            . '</head><body' . $direction . '>'
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
        $given = array_filter($sides, fn (string $side): bool => isset($this->styles[$side]));
        if ($given === []) {
            return '';
        }

        $margins = array_map(fn (string $side): string => (is_int($this->styles[$side] ?? null) || is_float($this->styles[$side] ?? null) ? $this->styles[$side] : 0) . 'mm', $sides);

        return ' @page { margin: ' . implode(' ', $margins) . '; }';
    }

    private function stringStyle(string $name): string
    {
        $value = $this->styles[$name] ?? null;

        return is_string($value) ? $value : (string) self::DEFAULT_STYLES[$name];
    }

    private function numberStyle(string $name): int|float
    {
        $value = $this->styles[$name] ?? null;

        return is_int($value) || is_float($value) ? $value : (int) self::DEFAULT_STYLES[$name];
    }
}
