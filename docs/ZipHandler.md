# ZipHandler

The `ZipHandler` class in the PHP EPUB Processor library is responsible for handling ZIP file operations.
It provides methods to extract contents from a ZIP file and to compress a directory into a ZIP file, facilitating the manipulation of EPUB files which are essentially ZIP archives.

## Key Methods

- **`extract(string $zipFilePath, string $destination): void`**: Extracts the contents of a ZIP file to the specified directory. Throws an exception if the ZIP file cannot be opened or extracted.

- **`compress(string $source, string $zipFilePath): void`**: Compresses a directory into a ZIP file at the specified path. Throws an exception if the ZIP file cannot be created or if the source directory is invalid.

## Usage Example

```php
use PhpEpub\ZipHandler;

$zipHandler = new ZipHandler();

try {
    // Extract a ZIP file
    $zipHandler->extract('/path/to/file.zip', '/path/to/destination');
    echo "ZIP file extracted successfully.";

    // Compress a directory into a ZIP file
    $zipHandler->compress('/path/to/source', '/path/to/output.zip');
    echo "Directory compressed into ZIP file successfully.";
} catch (Exception $e) {
    echo "Error handling ZIP file: " . $e->getMessage();
}
```
