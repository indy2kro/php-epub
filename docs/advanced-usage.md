# Advanced Usage

## Modifying EPUB Content

To modify the content within an EPUB file, you can use the ContentManager class to access specific files, make changes, and save them.

```php
use PhpEpub\EpubFile;

// Load the EPUB file
$epubFile = EpubFile::open('/path/to/your.epub');

// Access the content manager (it keeps the manifest, spine and table of contents in sync)
$contentManager = $epubFile->getContentManager();

// Retrieve and modify content; paths are relative to the book root
$contentFile = 'EPUB/text/chapter1.xhtml';
$content = $contentManager->getContent($contentFile);
$modifiedContent = str_replace('Old Text', 'New Text', $content);

// Save the modified content
$contentManager->updateContent($contentFile, $modifiedContent);
```

### Saving Changes

`EpubFile::save()` writes everything that changed since `load()`:

1. Pending metadata, manifest and spine changes are written to the OPF file (the modification date is refreshed, and the NCX `docTitle` follows the title). Calling `Metadata::save()` yourself first is optional.
2. The extracted directory is packaged as an OCF-valid archive: `mimetype` first, uncompressed and exactly `application/epub+zip` (a wrong one is corrected), `/` separators on every OS.

Changes are only in the temporary directory until `save()` is called; `load()` again or `cleanup()` discards them.

#### Save to the Same File

To save changes to the same EPUB file:

```php
$epubFile->save();
```

This will overwrite the original EPUB file with the modified content.

#### Save as a New File

To save the modified EPUB as a new file:

```php
$newEpubFilePath = '/path/to/new.epub';
$epubFile->save($newEpubFilePath);
```

This will create a new EPUB file with the changes, leaving the original file unchanged.

## Converting EPUB

Pass an adapter to `EpubFile::convert()`; the book's unsaved changes are included:

```php
use PhpEpub\Converters\CalibreAdapter;
use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\Converters\TCPDFAdapter;
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/your.epub');

// PDF with Dompdf or TCPDF (both pure PHP)
$epubFile->convert(new DompdfAdapter(['paper_size' => 'A5']), '/path/to/output.pdf');
$epubFile->convert(new TCPDFAdapter(['font_size' => 11]), '/path/to/output.pdf');

// MOBI, AZW3 and other formats with Calibre's ebook-convert (found on the PATH by default)
$epubFile->convert(new CalibreAdapter(['extra_args' => ['--output-profile', 'kindle']]), '/path/to/output.mobi');
```

The adapters also convert an extracted book directory directly (`$adapter->convert('/path/to/extracted/epub', $output)`), and `CalibreAdapter` takes an `.epub` file as well. See [Converter](converter.md) for every option and for how the PDF adapters read a book.

## Handling Untrusted EPUBs

An EPUB is a ZIP of XML and HTML, so a book uploaded by a user can be hostile. php-epub treats every book as untrusted input:

| Risk | What php-epub does |
|---|---|
| Entry names or OPF/manifest hrefs with `../` or absolute paths (path traversal) | Rejected by `PathResolver`; nothing is read or written outside the extraction directory |
| Zip bombs | `ZipHandler` limits entry count, total extracted size and per-entry compression ratio, measured on the bytes actually written (`EpubReader` measures the bytes it reads) |
| Huge or deeply nested XML and XHTML | `Limits::$maxXmlBytes` and `Limits::$maxHtmlBytes` refuse a document before it is read; libxml's depth limit is never lifted (`LIBXML_PARSEHUGE` is not used) |
| XML entity expansion and external entities (XXE) | `<!ENTITY` declarations are rejected in any encoding (checked on the parsed DOCTYPE, not only the raw bytes) and the parser never fetches network resources |
| Symlinks in the extraction directory | Cleanup deletes the link itself, never its target |
| Scripts, local files and remote URLs in book HTML during PDF conversion | Documents are parsed as HTML; scripts and embeds are removed, CSS `url()`s, every resource attribute and the references inside SVG images are limited to files inside the book, and Dompdf runs without remote access, PHP or JavaScript, confined to the book directory |
| Shell arguments for Calibre, and conversions that hang | Calibre is started without a shell, so no argument is interpreted, and a conversion is stopped after `timeout` seconds (600 by default) |

The extraction directory is created with an unpredictable name and owner-only permissions, and is removed by `EpubFile::close()` (or `cleanup()`) or when the object is destroyed; a failed open removes it too. `EpubReader` creates none.

Use `Limits::web()` for user uploads: it caps the entry count, the total size, the compression ratio and the size of each XML or XHTML document (see [EpubReader and Limits](epub-reader.md)). To inspect an upload without extracting it, use `EpubReader`. For finer control, pass your own `ZipHandler`:

```php
use PhpEpub\EpubFile;
use PhpEpub\InvalidEpubException;
use PhpEpub\ZipException;
use PhpEpub\ZipHandler;

$zipHandler = new ZipHandler(
    maxEntries: 2_000,
    maxUncompressedBytes: 200 * 1024 * 1024,
    maxCompressionRatio: 50,
);

try {
    $epubFile = EpubFile::open($uploadedPath, $zipHandler);
} catch (ZipException $e) {
    // Not a readable archive, or it exceeds the limits.
} catch (InvalidEpubException $e) {
    // Readable archive, but not a valid (or a hostile) EPUB.
}
```

All exceptions extend `PhpEpub\Exception`:

- `InvalidEpubException`: invalid structure or a path outside the book; `XmlException` (a subclass) for unparseable or unsafe XML.
- `ZipException`: the archive cannot be read, extracted or written, or exceeds a limit.
- `ConversionException`: a converter could not read the book or write its output.
- `ReadOnlyException`: a change was asked of a book opened with `EpubReader`.
