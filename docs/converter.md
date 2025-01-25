# Converter

The `Converter` class in the PHP EPUB Processor library is responsible for converting EPUB files into various formats.
It utilizes different converter adapters to handle specific formats, such as PDF or MOBI, providing a flexible and extensible conversion system.

## Key Methods

- **`__construct(string $epubDirectory, array $adapters)`**: Initializes the `Converter` with the directory containing the EPUB contents and a map of format-specific converter adapters. Throws an exception if the directory does not exist.

- **`convert(string $format, string $outputPath): void`**: Converts the EPUB to the specified format using the appropriate adapter. Throws an exception if the format is not supported or if the conversion fails.

## Usage Example

```php
use PhpEpub\Converter;
use PhpEpub\Converters\CalibreAdapter;
use PhpEpub\Converters\DompdfAdapter;

$epubDirectory = '/path/to/extracted/epub';
$adapters = [
    'pdf' => new DompdfAdapter(),
    'mobi' => new CalibreAdapter(),
];

$converter = new Converter($epubDirectory, $adapters);

try {
    $converter->convert('pdf', '/path/to/output.pdf');
    echo "EPUB successfully converted to PDF.";

    $converter->convert('mobi', '/path/to/output.mobi');
    echo "EPUB successfully converted to PDF.";
} catch (Exception $e) {
    echo "Conversion failed: " . $e->getMessage();
}
```