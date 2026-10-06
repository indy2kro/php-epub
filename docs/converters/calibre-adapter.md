# CalibreAdapter

The `CalibreAdapter` class converts EPUB files to other formats (MOBI, AZW3, PDF, DOCX, …) with Calibre's `ebook-convert` command-line tool.

## Key Methods

- **`__construct(array $options = [], FileSystemHelper $helper = new FileSystemHelper(), ZipHandler $zipHandler = new ZipHandler())`**: Options:
    - `calibre_path`: path to `ebook-convert` (default `/usr/bin/ebook-convert`).
    - `extra_args`: a **list** of extra `ebook-convert` arguments. Each one is shell-escaped individually. Passing a single string is deprecated: it is inserted into the command unescaped and triggers an `E_USER_DEPRECATED` notice.

- **`convert(string $inputFile, string $outputPath): void`**: Converts an `.epub` file, or a directory with an extracted EPUB (packaged into a temporary `.epub` first, which is removed afterwards). The output extension selects the format. Every part of the command is escaped and Calibre's error output is included in the exception message. Throws an exception if Calibre or the input is missing, or if the conversion fails.

## Usage Example

```php
use PhpEpub\Converters\CalibreAdapter;

$calibreAdapter = new CalibreAdapter([
    'calibre_path' => '/usr/local/bin/ebook-convert',
    'extra_args' => ['--output-profile', 'kindle', '--title', $userSuppliedTitle],
]);

try {
    $calibreAdapter->convert('/path/to/input.epub', '/path/to/output.mobi');
    echo "EPUB successfully converted to MOBI.";
} catch (Exception $e) {
    echo "Conversion failed: " . $e->getMessage();
}
```
