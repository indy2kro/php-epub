# ZipHandler

The `ZipHandler` class in the PHP EPUB Processor library is responsible for handling ZIP file operations.
It provides methods to extract contents from a ZIP file and to compress a directory into a ZIP file, facilitating the manipulation of EPUB files which are essentially ZIP archives.

## Constructor

- **`__construct(int $maxEntries = 10_000, int $maxUncompressedBytes = 1 GiB, int $maxCompressionRatio = 100)`**: Sets the limits applied when extracting. EPUBs are treated as untrusted input, so extraction stops with a `ZipException` when an archive has too many entries, when the extracted data would exceed `$maxUncompressedBytes`, or when an entry larger than 1 MiB inflates to more than `$maxCompressionRatio` times its compressed size (a typical "zip bomb"). Limits are enforced on the bytes actually written, not on the sizes the archive declares.

## Key Methods

- **`extract(string $zipFilePath, string $destination): void`**: Extracts the contents of a ZIP file to the specified directory. Entry names that are absolute or contain `..` segments escaping the destination are rejected, as are file entries whose names differ only in case or Unicode normalization (compared after Unicode case folding and NFC normalization, so `É.txt` and `é.txt`, or a precomposed and a decomposed `é`, collide), which OCF forbids and which would overwrite each other on Windows and macOS. The folding needs the `mbstring` and `intl` extensions; without them only ASCII case is compared. On Windows, entry names that cannot be created there (a segment ending in a dot or a space, a reserved device name such as `CON` or `LPT1` with or without an extension, or one of `<>:"|?*` and control characters) are rejected with a `ZipException` naming the entry; on other systems such names keep loading. Throws a `ZipException` if the ZIP file cannot be opened or extracted, or if a limit is exceeded.

- **`compress(string $source, string $zipFilePath): void`**: Compresses a directory into a ZIP file at the specified path. The `mimetype` file is written first and uncompressed, and entry names always use `/`, as the EPUB OCF specification requires. Entries are written in sorted order with a fixed modification time and fixed Unix permissions, so saving the same book twice produces identical archives (ZIP stores local time, so this holds within one time zone). The archive is written to a temporary file next to the target and moved into place when complete, so a failure leaves neither a partial file nor damage to an existing one. Every libzip call is checked: a `ZipException` names the entry and includes libzip's status string (also when finalizing fails). Throws a `ZipException` if the ZIP file cannot be created, the source directory is invalid or has no files.

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
