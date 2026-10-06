# TCPDFAdapter

The `TCPDFAdapter` class in the PHP EPUB Processor library is responsible for converting EPUB content into PDF format using the TCPDF library.
It provides a flexible interface to customize the PDF output through various styling options.

Requires `tecnickcom/tcpdf` and its core fonts; see [Installation](../installation.md#tcpdf-core-fonts).

## Key Methods

- **`__construct(array $styles = [])`**: Optional styling parameters: `font` (default `helvetica`), `font_size` (default `12`), `margin_left`/`margin_top`/`margin_right`/`margin_bottom` in mm (defaults `15`/`27`/`15`/`25`), `header`/`footer` booleans (default `true`; the header shows the book title and authors), and `paper_size` (e.g. `A4`, `letter`; default `A4`) and `orientation` (`portrait` or `landscape`; default `portrait`), as in `DompdfAdapter`. Values of the wrong type fall back to the defaults.

- **`convert(string $epubDirectory, string $outputPath): void`**: Renders every spine document in reading order, each on a new page, sets the PDF title and author from the EPUB metadata, and writes the PDF. Image sources are limited to files inside the book; see [Converter](../converter.md#how-the-pdf-adapters-read-a-book). Throws a `ConversionException` if the book cannot be read or the PDF cannot be written.

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
