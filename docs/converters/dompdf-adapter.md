# DompdfAdapter

The `DompdfAdapter` class converts EPUB content into PDF using the Dompdf library (`composer require dompdf/dompdf`).

## Key Methods

- **`__construct(array $styles = [])`**: Optional styling parameters:

    | Style | Type | Default | |
    | --- | --- | --- | --- |
    | `font` | string | `DejaVu Sans` | A font family Dompdf knows. |
    | `font_size` | int or float | `12` | In points; greater than zero. |
    | `paper_size` | string | `A4` | A size Dompdf knows (any case), as in `TCPDFAdapter`. |
    | `orientation` | string | `portrait` | `portrait` or `landscape`. |
    | `margin_top`, `margin_right`, `margin_bottom`, `margin_left` | int or float | none | In mm (`12.5` is fine; not negative), as in `TCPDFAdapter`. Without any margin option Dompdf keeps its own default margins; once one is given, sides that are not given are `0`. |

    The constructor throws an `Exception` for a style it does not know (the message lists the valid ones), a value of the wrong type (a numeric string is not a number), an unusable value (a negative margin, an unknown orientation) and a paper size Dompdf does not know (Dompdf itself would fall back to `letter` without a word).

    **Fonts and languages:** the default font is DejaVu Sans, which Dompdf ships and which covers Latin, Greek, Cyrillic, Hebrew and Arabic, not only Latin-1 like the PDF core fonts (`Arial` is mapped to Helvetica). Chinese, Japanese and Korean need a font installed in Dompdf: pass its family as `font`, which always wins. Books with `page-progression-direction="rtl"` in the spine, or (when the spine says nothing) with a primary language of `ar`, `he`, `fa`, `ur`, `yi`, `ps`, `sd`, `ug` or `dv`, get `dir="rtl"` on the generated `<html>` and `<body>`; the book's language is set as `lang`. Dompdf does not shape Arabic script, so Arabic letters are not joined.

    **Embedded fonts:** the fonts a book loads with `@font-face` are used. Obfuscated fonts (IDPF or Adobe, listed in `META-INF/encryption.xml`) are de-obfuscated in memory and handed to Dompdf as `data:` URIs, so nothing is written into the book; a font that cannot be de-obfuscated (an Adobe font in a book without a `urn:uuid:` identifier) is dropped. Dompdf loads only TrueType fonts (a `src` without `format()`, or `format("truetype")`). Dompdf keeps the fonts it loads in a font directory; each conversion gets a private temporary one, deleted afterwards, so books leave nothing in Dompdf's own `lib/fonts` and cannot change the fonts of later conversions.

    A DRM-protected book is refused with a `ConversionException`.

    Dompdf cannot write a PDF outline, so use `TCPDFAdapter` when you need chapter bookmarks.

- **`convert(string $epubDirectory, string $outputPath): void`**: Renders every spine document in reading order, each on a new page, sets the PDF title and author from the EPUB metadata, and writes the PDF. Throws a `ConversionException` if the book cannot be read, Dompdf fails (its exception is kept as the previous exception) or the PDF cannot be written.

- **`buildHtml(string $epubDirectory): string`**: Returns the HTML document that `convert()` renders, e.g. for previews or debugging.

Dompdf runs with remote resources, PHP and JavaScript disabled and its file access limited to the book directory; see [Converter](../converter.md#how-the-pdf-adapters-read-a-book).

## Usage Example

```php
use PhpEpub\ConversionException;
use PhpEpub\Converters\DompdfAdapter;

$dompdfAdapter = new DompdfAdapter([
    'font' => 'Times New Roman',
    'font_size' => 14,
    'paper_size' => 'A4',
    'orientation' => 'landscape',
]);

try {
    $dompdfAdapter->convert('/path/to/extracted/epub', '/path/to/output.pdf');
    echo "EPUB successfully converted to PDF.";
} catch (ConversionException $e) {
    echo "Conversion failed: " . $e->getMessage();
}
```
