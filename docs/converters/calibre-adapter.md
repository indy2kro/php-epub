# CalibreAdapter

The `CalibreAdapter` class converts EPUB files to other formats (MOBI, AZW3, PDF, DOCX, …) with Calibre's `ebook-convert` command-line tool.

## Key Methods

- **`__construct(array $options = [], FileSystemHelper $helper = new FileSystemHelper(), ZipHandler $zipHandler = new ZipHandler())`**: Options:
    - `calibre_path`: path to `ebook-convert`. When it is not given, `ebook-convert` is looked up on the `PATH`, then in the usual install locations (`/usr/bin`, `/usr/local/bin`, `/opt/calibre`, `/Applications/calibre.app/Contents/MacOS`, `C:\Program Files\Calibre2`, `C:\Program Files (x86)\Calibre2`); `convert()` throws if it is not found.
    - `extra_args`: a **list** of extra `ebook-convert` arguments, each passed to Calibre exactly as given. Passing a single string is deprecated (it triggers an `E_USER_DEPRECATED` notice): it is split into arguments at spaces, with `"double"` or `'single'` quotes grouping words, and nothing else is interpreted.
    - `timeout`: seconds before a conversion is stopped (default `600`); `null` waits indefinitely. Throws an `Exception` at construction for zero or a negative value.

- **`convert(string $inputFile, string $outputPath): void`**: Converts an `.epub` file, or a directory with an extracted EPUB (packaged into a temporary `.epub` first, which is removed afterwards). The output extension selects the format. Calibre is started directly, without a shell, so no argument is ever interpreted; its output (including stderr) is included in the exception message when the conversion fails. A conversion that runs longer than `timeout` is stopped (the `ebook-convert` process is killed) and reported with an exception. Throws an exception if Calibre or the input is missing, or if the conversion fails.

A relative input or output path that starts with `-` is passed to Calibre as `./-name` (`.\-name` on Windows), so Calibre never reads it as an option.

## Untrusted books

Unlike `TCPDFAdapter` and `DompdfAdapter`, which sanitise the book and confine every resource to it (see [Converter](../converter.md#how-the-pdf-adapters-read-a-book)), `CalibreAdapter` hands the book to Calibre unchanged. Whether Calibre keeps the book's references inside the book depends on its version. When you convert books you do not trust, keep Calibre up to date, or run `ebook-convert` in a sandbox (a container, or an unprivileged user without access to files that matter).

## Usage Example

```php
use PhpEpub\Converters\CalibreAdapter;

$calibreAdapter = new CalibreAdapter([
    'calibre_path' => '/usr/local/bin/ebook-convert',
    'extra_args' => ['--output-profile', 'kindle', '--title', $userSuppliedTitle],
    'timeout' => 120,
]);

try {
    $calibreAdapter->convert('/path/to/input.epub', '/path/to/output.mobi');
    echo "EPUB successfully converted to MOBI.";
} catch (Exception $e) {
    echo "Conversion failed: " . $e->getMessage();
}
```
