# TCPDFAdapter

The `TCPDFAdapter` class in the PHP EPUB Processor library is responsible for converting EPUB content into PDF format using the TCPDF library.
It provides a flexible interface to customize the PDF output through various styling options.

## Key Methods

- **`__construct(array $styles = [])`**: Initializes the `TCPDFAdapter` with optional styling parameters, such as font, font size, margins, and whether to include headers and footers. These parameters can be customized to alter the appearance of the generated PDF.

- **`convert(string $epubDirectory, string $outputPath): void`**: Converts the EPUB content to a PDF using TCPDF. It sets document information, applies styles, adds pages, writes content, and saves the PDF to the specified output path. Throws an exception if the conversion fails.

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
