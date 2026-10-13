# EpubReader and Limits

`EpubReader` reads a book **without extracting it**: nothing is written to disk, and entries are read from the archive only when asked for. It suits a server that inspects an uploaded book per request (title, authors, table of contents, cover, text), where `EpubFile::open()` would extract the whole archive into a temporary directory first.

`Limits` is the set of resource limits for a book that is untrusted input. Both `EpubFile` and `EpubReader` accept one.

## Limits

```php
public function __construct(
    int $maxEntries = 10_000,
    int $maxUncompressedBytes = 1024 * 1024 * 1024,
    int $maxCompressionRatio = 100,
    int $maxXmlBytes = PHP_INT_MAX,
    int $maxHtmlBytes = PHP_INT_MAX,
    int $maxArchiveBytes = PHP_INT_MAX
)
```

- `Limits::default()`: what the library does without any configuration (10,000 entries, 1 GiB, ratio 100, no cap on single documents or the archive).
- `Limits::web()`: for a public service processing uploads per request: 2,000 entries, 200 MiB, ratio 100, 8 MiB per XML or XHTML document, and a 100 MiB archive.

| Limit | Applies to |
|---|---|
| `maxEntries`, `maxUncompressedBytes`, `maxCompressionRatio` | The archive, as `ZipHandler` limits an extraction: measured on the bytes actually read, not on the sizes the archive declares |
| `maxArchiveBytes` | The compressed archive that `openString()` and `openStream()` accept: a string is checked up front and a stream is copied to its scratch file only up to one byte past the cap, so an oversized upload cannot fill the temp disk; both throw a `ZipException`. A book opened from a path is already on disk |
| `maxXmlBytes` | Every XML document the library parses (container, package, navigation, NCX, `encryption.xml`): `XmlParser` checks the size before reading and throws an `XmlException` |
| `maxHtmlBytes` | Every XHTML or HTML content document the library parses as markup: `getText()` throws an `InvalidEpubException`, `EpubDocumentLoader` (conversion) a `ConversionException`; the lookup of a cover page's first image is best effort and treats a too large page as having no image |

No limit passes `LIBXML_PARSEHUGE`, so libxml's own depth limit still refuses absurdly nested documents (with an `XmlException`).

```php
use PhpEpub\EpubFile;
use PhpEpub\Limits;

// Same as passing a ZipHandler and an XmlParser by hand
$epubFile = EpubFile::open('/path/to/upload.epub', limits: Limits::web());

// Your own numbers
$limits = new Limits(maxEntries: 500, maxUncompressedBytes: 50 * 1024 * 1024, maxXmlBytes: 1024 * 1024, maxHtmlBytes: 2 * 1024 * 1024);
$epubFile = EpubFile::openString($uploadedBytes, limits: $limits);
$epubFile->close();
```

A `ZipHandler` or `XmlParser` passed to `open()` replaces that part of the limits. `EpubFile::convert()` carries the book's `maxXmlBytes` and `maxHtmlBytes` into the adapter's `EpubDocumentLoader` (every adapter except Calibre implements `LimitsAwareConverter::withLimits()`): the smaller of the loader's own cap and the book's applies, so a book opened with `Limits::web()` is converted under the same caps with a plain `new HtmlAdapter()`. An adapter used directly on a directory, outside `convert()`, takes its caps from its loader: `new DompdfAdapter([], new EpubDocumentLoader(maxHtmlBytes: Limits::web()->maxHtmlBytes))`. A `Merger` parses and rewrites each book's documents with that book's `XmlParser`, or with the one passed to it.

## Opening

```php
public static function open(string $filePath, ?Limits $limits = null, ?XmlParser $xmlParser = null): EpubReader
public static function openString(string $data, ?Limits $limits = null, ?XmlParser $xmlParser = null): EpubReader
public static function openStream($stream, ?Limits $limits = null, ?XmlParser $xmlParser = null): EpubReader
```

`open()` reads the file in place. `openString()` reads the data in place where ext-zip has `ZipArchive::openString()` (newer builds only); otherwise, and for `openStream()`, `ZipArchive` needs a real file, so the data is buffered in one private scratch file (random name, mode `0700`) that `close()` deletes. No extraction directory is ever created.

When the book is opened, the entry count, the entry names (they must stay inside the book and be unique after case folding, as `ZipHandler` requires) and the declared total size are checked, and the container and the package document are read and validated. A book that fails any of that throws, and nothing is left behind.

## Reading

```php
public function getMetadata(): Metadata
public function getManifest(): Manifest
public function getSpine(): Spine
public function getTableOfContents(): TableOfContents
public function getCoverImage(): ?ManifestItem
public function getText(bool $linearOnly = true): array
public function getContentPaths(): array
public function hasContent(string $path): bool
public function getContent(string $path, ?int $maxBytes = null): string
public function getContentSize(string $path): int
public function isDrmProtected(): bool
public function getEncryptedPaths(): array
public function toArray(): array
public function close(): void
```

They return the same results as the `EpubFile` methods of the same name (the tests compare them on the fixture books). `toArray()` is the JSON-ready summary of [`EpubFile::toArray()`](epub-file.md), `isDrmProtected()` and `getEncryptedPaths()` the DRM check, all without extracting; `getContentSize()` is the uncompressed size the archive declares for a file. `toArray()` reads each linear document once to count its words (documents that are encrypted, unreadable or over `maxHtmlBytes` add nothing). `getContent()` holds the whole file in memory: pass `$maxBytes` (or keep the limits within your `memory_limit`) when you read files you do not know.

Every entry read counts once towards `maxUncompressedBytes`, however often it is read again, and is checked against the compression ratio while it is streamed.

```php
use PhpEpub\EpubReader;
use PhpEpub\Exception;
use PhpEpub\Limits;

try {
    $reader = EpubReader::open($uploadedPath, Limits::web());

    echo $reader->getMetadata()->getTitle(), "\n";
    foreach ($reader->getTableOfContents()->getEntries() as $entry) {
        echo $entry->title, "\n";
    }

    $cover = $reader->getCoverImage();
    if ($cover !== null) {
        $bytes = $reader->getContent($cover->path, 5 * 1024 * 1024);
    }

    $reader->close();
} catch (Exception $e) {
    // Not an EPUB, or a limit was exceeded.
}
```

## Read-only

There is no `save()`, content manager, validator or conversion. The `Metadata` and `TableOfContents` objects are the ones `EpubFile` uses, so they have setters; in a reader, `Metadata::save()` and every table of contents change throw a `ReadOnlyException` (a `PhpEpub\Exception`), and edits made to the metadata, manifest or spine in memory are never written anywhere. To edit, validate or convert a book, open it with `EpubFile`.

`close()` is optional (the destructor closes the reader) and safe to call twice; a closed reader throws on use.
