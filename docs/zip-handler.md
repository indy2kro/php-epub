# ZipHandler

The `ZipHandler` class in the PHP EPUB Processor library is responsible for handling ZIP file operations.
It provides methods to extract contents from a ZIP file and to compress a directory into a ZIP file, facilitating the manipulation of EPUB files which are essentially ZIP archives.

## Constructor

- **`__construct(int $maxEntries = 10_000, int $maxUncompressedBytes = 1 GiB, int $maxCompressionRatio = 100)`**: Sets the limits applied when extracting. EPUBs are treated as untrusted input, so extraction stops with a `ZipException` when an archive has too many entries, when the extracted data would exceed `$maxUncompressedBytes`, or when an entry larger than 1 MiB inflates to more than `$maxCompressionRatio` times its compressed size (a typical "zip bomb"). Limits are enforced on the bytes actually written, not on the sizes the archive declares.

## Key Methods

- **`extract(string $zipFilePath, string $destination): void`**: Extracts the contents of a ZIP file to the specified directory. Entry names that are absolute or contain `..` segments escaping the destination are rejected. Throws a `ZipException` if the ZIP file cannot be opened or extracted, or if a limit is exceeded.

- **`compress(string $source, string $zipFilePath): void`**: Compresses a directory into a ZIP file at the specified path. The `mimetype` file is written first and uncompressed, and entry names always use `/`, as the EPUB OCF specification requires. Throws a `ZipException` if the ZIP file cannot be created or if the source directory is invalid.

## Exceptions

`ZipException` extends `PhpEpub\Exception`, so existing `catch (PhpEpub\Exception $e)` blocks keep working.

## Usage Example

```php
use PhpEpub\ZipException;
use PhpEpub\ZipHandler;

// Tighter limits for user uploads
$zipHandler = new ZipHandler(maxEntries: 2_000, maxUncompressedBytes: 200 * 1024 * 1024);

try {
    // Extract a ZIP file
    $zipHandler->extract('/path/to/file.zip', '/path/to/destination');
    echo "ZIP file extracted successfully.";

    // Compress a directory into a ZIP file
    $zipHandler->compress('/path/to/source', '/path/to/output.zip');
    echo "Directory compressed into ZIP file successfully.";
} catch (ZipException $e) {
    echo "Error handling ZIP file: " . $e->getMessage();
}
```
