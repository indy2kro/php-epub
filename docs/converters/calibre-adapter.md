# CalibreAdapter

The `CalibreAdapter` class in the PHP EPUB Processor library is responsible for converting EPUB files to other formats using the Calibre command-line tool.
It provides a flexible interface to execute conversions by leveraging Calibre's powerful ebook-convert utility.

## Key Methods

- **`__construct(array $options = [], FileSystemHelper $helper = new FileSystemHelper())`**: Initializes the `CalibreAdapter` with optional command-line options for Calibre and a file system helper. Default options include the path to the Calibre executable and any extra arguments.

- **`convert(string $inputFile, string $outputPath): void`**: Converts an EPUB file to another format using Calibre. It constructs and executes a command-line call to `ebook-convert`. Throws an exception if the conversion fails or if the Calibre tool or input file is not found.

## Usage Example

```php
use PhpEpub\Converters\CalibreAdapter;
use PhpEpub\Util\FileSystemHelper;

$options = [
    'calibre_path' => '/usr/local/bin/ebook-convert',
    'extra_args' => '--output-profile kindle',
];

$calibreAdapter = new CalibreAdapter($options, new FileSystemHelper());

try {
    $calibreAdapter->convert('/path/to/input.epub', '/path/to/output.mobi');
    echo "EPUB successfully converted to MOBI.";
} catch (Exception $e) {
    echo "Conversion failed: " . $e->getMessage();
```
