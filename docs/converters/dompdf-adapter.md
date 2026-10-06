# DompdfAdapter

The `DompdfAdapter` class converts EPUB content into PDF using the Dompdf library (`composer require dompdf/dompdf`).

## Key Methods

- **`__construct(array $styles = [])`**: Optional styling parameters: `font` (default `Arial`), `font_size` in points (default `12`), `paper_size` (default `A4`), `orientation` (default `portrait`) and `margin_top`/`margin_right`/`margin_bottom`/`margin_left` in mm, as in `TCPDFAdapter`. Without any margin option Dompdf keeps its own default margins; once one is given, sides that are not given are `0`. Values of the wrong type fall back to the defaults.

- **`convert(string $epubDirectory, string $outputPath): void`**: Renders every spine document in reading order, each on a new page, sets the PDF title and author from the EPUB metadata, and writes the PDF. Throws a `ConversionException` if the book cannot be read or the PDF cannot be written.

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
