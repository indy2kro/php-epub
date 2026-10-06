# Advanced Usage

## Modifying EPUB Content

To modify the content within an EPUB file, you can use the ContentManager class to access specific files, make changes, and save them.

```php
use PhpEpub\EpubFile;
use PhpEpub\ContentManager;

$epubFilePath = '/path/to/your.epub';

// Load the EPUB file
$epubFile = new EpubFile($epubFilePath);
$epubFile->load();

// Access the content manager (it keeps the manifest and spine in sync)
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

1. Pending metadata, manifest and spine changes are written to the OPF file (for EPUB 3, `dcterms:modified` is refreshed). Calling `Metadata::save()` yourself first is optional.
2. The extracted directory is packaged as an OCF-valid archive: `mimetype` first and uncompressed, `/` separators on every OS.

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

You can convert an EPUB to PDF using one of the available adapters.


### Convert to PDF Using DompdfAdapter

```php
use PhpEpub\Converters\DompdfAdapter;

$dompdfAdapter = new DompdfAdapter();
$dompdfAdapter->convert('/path/to/extracted/epub', '/path/to/output.pdf');
```

### Convert to PDF Using TCPDFAdapter

To convert using TCPDF:

```php
use PhpEpub\Converters\TCPDFAdapter;

$tcpdfAdapter = new TCPDFAdapter();
$tcpdfAdapter->convert('/path/to/extracted/epub', '/path/to/output.pdf');
```

### Convert to MOBI Using CalibreAdapter

To convert using Calibre:

```php
use PhpEpub\Converters\CalibreAdapter;

$options = [
    'calibre_path' => '/usr/bin/ebook-convert',
    'extra_args' => ['--output-profile', 'kindle'],
];

$calibreAdapter = new CalibreAdapter($options);
$calibreAdapter->convert('/path/to/input.epub', '/path/to/output.mobi');
```

## Handling Untrusted EPUBs

An EPUB is a ZIP of XML and HTML, so a book uploaded by a user can be hostile. php-epub treats every book as untrusted input:

| Risk | What php-epub does |
|---|---|
| Entry names or OPF/manifest hrefs with `../` or absolute paths (path traversal) | Rejected by `PathResolver`; nothing is read or written outside the extraction directory |
| Zip bombs | `ZipHandler` limits entry count, total extracted size and per-entry compression ratio, measured on the bytes actually written |
| XML entity expansion and external entities (XXE) | `<!ENTITY` declarations are rejected and the parser never fetches network resources |
| Symlinks in the extraction directory | Cleanup deletes the link itself, never its target |
| Scripts, local files and remote URLs in book HTML during PDF conversion | Scripts are removed, image sources are limited to files inside the book, and Dompdf runs without remote access, PHP or JavaScript, confined to the book directory |
| Shell arguments for Calibre | Every argument is escaped (pass `extra_args` as a list) |

The extraction directory is created with an unpredictable name and owner-only permissions, and is removed by `EpubFile::cleanup()` or when the object is destroyed.

Tighten the limits for user uploads by passing your own `ZipHandler`:

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
    $epubFile = new EpubFile($uploadedPath, $zipHandler);
    $epubFile->load();
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
