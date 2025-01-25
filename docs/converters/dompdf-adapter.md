# DompdfAdapter

The `DompdfAdapter` class in the PHP EPUB Processor library is responsible for converting EPUB content into PDF format using the Dompdf library.
It provides a flexible interface to customize the PDF output through various styling options.

## Key Methods

- **`__construct(array $styles = [])`**: Initializes the `DompdfAdapter` with optional styling parameters, such as font, font size, paper size, and orientation. These parameters can be customized to alter the appearance of the generated PDF.

- **`convert(string $epubDirectory, string $outputPath): void`**: Converts the EPUB content to a PDF using Dompdf. It loads the content, sets the paper size and orientation, renders the PDF, and saves it to the specified output path. Throws an exception if the conversion fails.

## Usage Example

```php
use PhpEpub\Converters\DompdfAdapter;

$styles = [
    'font' => 'Times New Roman',
    'font_size' => 14,
    'paper_size' => 'A4',
    'orientation' => 'landscape',
];

$dompdfAdapter = new DompdfAdapter($styles);

try {
    $dompdfAdapter->convert('/path/to/extracted/epub', '/path/to/output.pdf');
    echo "EPUB successfully converted to PDF.";
} catch (Exception $e) {
    echo "Conversion failed: " . $e->getMessage();
}
```
