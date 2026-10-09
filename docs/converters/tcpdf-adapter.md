# TCPDFAdapter

The `TCPDFAdapter` class in the PHP EPUB Processor library is responsible for converting EPUB content into PDF format using the TCPDF library.
It provides a flexible interface to customize the PDF output through various styling options.

Requires `tecnickcom/tcpdf` and its core fonts; see [Installation](../installation.md#tcpdf-core-fonts).

## Key Methods

- **`__construct(array $styles = [], EpubDocumentLoader $loader = new EpubDocumentLoader(), ?PdfConversionOptions $options = null)`**: Optional styling parameters (the options, such as limits, a contents page and a custom page size, are described in [Converter](../converter.md#limits-and-options-for-untrusted-books); without options the adapter behaves as before, and with options fixed-layout books are refused unless `allowFixedLayout` is set):

    | Style | Type | Default | |
    | --- | --- | --- | --- |
    | `font` | string | `dejavusans` | A TCPDF font name. |
    | `font_size` | int or float | `12` | In points; greater than zero. |
    | `margin_left`, `margin_top`, `margin_right`, `margin_bottom` | int or float | `15`, `27`, `15`, `25` | In mm (`12.5` is fine); not negative. |
    | `header`, `footer` | bool | `true` | The header shows the book title and authors. |
    | `paper_size` | string | `A4` | A format TCPDF knows, such as `A4` or `letter` (any case), as in `DompdfAdapter`. |
    | `orientation` | string | `portrait` | `portrait` or `landscape`, as in `DompdfAdapter`. |
    | `bookmarks` | bool | `true` | A PDF outline entry per chapter, titled with the chapter's first `h1`–`h3` heading, else its `<title>`, else "Chapter N". |

    The constructor throws an `Exception` for a style it does not know (the message lists the valid ones), a value of the wrong type (a numeric string is not a number), an unusable value (a negative margin, an empty font, an unknown orientation) and a paper size TCPDF does not know. With TCPDF 6 the paper size is not checked, because TCPDF 6 has no list to check it against.

    **Fonts and languages:** the default font is DejaVu Sans, which covers Latin, Greek, Cyrillic, Hebrew and Arabic, not only Latin-1 like the PDF core fonts (`helvetica`, `times`, `courier`). It is generated, with its bold and italic variants, by `scripts/generate-core-fonts.php` (see [Installation](../installation.md#tcpdf-core-fonts)); when that has not been run since DejaVu Sans was added, the default falls back to `helvetica`, which renders only Latin-1 text. Chinese, Japanese and Korean need a font of your own: pass its name as `font`. A `font` you pass always wins and never falls back. Books with `page-progression-direction="rtl"` in the spine, or (when the spine says nothing) with a primary language of `ar`, `he`, `fa`, `ur`, `yi`, `ps`, `sd`, `ug` or `dv`, are rendered right to left.

    **Embedded fonts:** TCPDF does not support CSS `@font-face`, so the fonts a book embeds are not used (use `DompdfAdapter`, which loads them). A DRM-protected book is refused with a `ConversionException`.

- **`convert(string $epubDirectory, string $outputPath): void`**: Renders every spine document in reading order, each on a new page, sets the PDF title and author from the EPUB metadata, and writes the PDF. Image sources are limited to files inside the book; see [Converter](../converter.md#how-the-pdf-adapters-read-a-book). Throws a `ConversionException` if the book cannot be read, TCPDF fails (its exception, or any other error, is kept as the previous exception) or the PDF cannot be written. A missing font definition, TCPDF's "unable to read file: helvetica.json", is reported with the command that generates the fonts: `php vendor/indy2kro/php-epub/scripts/generate-core-fonts.php`.

## Usage Example

```php
use PhpEpub\Converters\TCPDFAdapter;

$styles = [
    'font' => 'times',
    'font_size' => 14,
    'margin_left' => 20,
    'margin_top' => 30,
    'margin_right' => 20,
    'margin_bottom' => 30,
    'header' => true,
    'footer' => false,
];

$tcpdfAdapter = new TCPDFAdapter($styles);

try {
    $tcpdfAdapter->convert('/path/to/extracted/epub', '/path/to/output.pdf');
    echo "EPUB successfully converted to PDF.";
} catch (Exception $e) {
    echo "Conversion failed: " . $e->getMessage();
}
```
