# Converter

The `Converter` class converts an extracted EPUB into other formats by dispatching to format-specific adapters (PDF via Dompdf or TCPDF, MOBI/AZW3/… via Calibre).

## Key Methods

- **`__construct(string $epubDirectory, array $adapters)`**: Initializes the `Converter` with the directory containing the extracted EPUB and a map of format => `ConverterInterface` adapter. Throws an exception if the directory does not exist.

- **`convert(string $format, string $outputPath): void`**: Converts the EPUB with the adapter registered for `$format`. Throws an exception if the format is not supported or if the conversion fails.

Every adapter accepts the extracted directory. `CalibreAdapter` also accepts an `.epub` file; given a directory, it packages it into a temporary `.epub` first.

## Usage Example

```php
use PhpEpub\ConversionException;
use PhpEpub\Converter;
use PhpEpub\Converters\CalibreAdapter;
use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\EpubFile;

$epubFile = new EpubFile('/path/to/book.epub');
$epubFile->load();

$converter = new Converter($epubFile->getTempDir(), [
    'pdf' => new DompdfAdapter(),
    'mobi' => new CalibreAdapter(['calibre_path' => '/usr/bin/ebook-convert']),
]);

try {
    $converter->convert('pdf', '/path/to/output.pdf');
    $converter->convert('mobi', '/path/to/output.mobi');
} catch (ConversionException $e) {
    echo "Conversion failed: " . $e->getMessage();
}
```

## How the PDF adapters read a book

`DompdfAdapter` and `TCPDFAdapter` render every XHTML document in the **spine**, in reading order, each starting on a new page, and take the PDF title and author from the EPUB metadata. A directory that only contains a `content.xhtml` file (the layout older versions required) is still accepted.

Book content is treated as untrusted:

- `<script>` elements are removed.
- Image sources are rewritten to files inside the book; absolute paths, `file://`, remote URLs, paths escaping the book and missing files are blanked. `data:` URIs are kept.
- Dompdf runs with remote resources, PHP and JavaScript disabled, and its file access limited (`chroot`) to the book directory.
