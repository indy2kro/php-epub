# EpubFile

The `EpubFile` class is the main entry point for the PHP EPUB Processor library. It provides a facade for loading, manipulating, and saving EPUB files, coordinating between metadata, spine, and content management components.

## Overview

EpubFile handles the complete lifecycle of working with EPUB files:

1. **Loading**: Extracts the ZIP archive to a temporary directory
2. **Parsing**: Locates and parses the OPF file, extracting metadata and spine information
3. **Manipulation**: Provides access to modify metadata, content, and structure
4. **Saving**: Compresses the modified contents back into an EPUB file

## Key Methods

### Constructor

```php
public function __construct(
    string $filePath,
    ?ZipHandler $zipHandler = null,
    ?XmlParser $xmlParser = null
)
```

Initializes the EpubFile with the path to an EPUB file. The optional `$zipHandler` and `$xmlParser` parameters allow dependency injection, e.g. a `ZipHandler` with tighter extraction limits.

```php
public static function open(string $filePath, ?ZipHandler $zipHandler = null, ?XmlParser $xmlParser = null): EpubFile
```

Shortcut for `new EpubFile(...)` followed by `load()`.

### Loading and Saving

```php
public function load(): void
```

Loads the EPUB file:
- Extracts ZIP contents to a temporary directory
- Parses `container.xml` to locate the OPF file
- Parses the OPF file to extract metadata, manifest and spine
- Initializes the ContentManager for file operations

Calling `load()` again discards the previous extraction (and any unsaved changes) and starts from the file on disk.

Throws an exception if the file cannot be opened or the EPUB structure is invalid.

```php
public function save(?string $filePath = null): void
```

Saves the modified EPUB back to disk. If `$filePath` is null, overwrites the original file. Pending metadata, manifest and spine changes are written to the OPF first. Throws an exception if called before `load()`.

### Accessing Components

```php
public function getMetadata(): Metadata
```

Returns the Metadata object for reading/updating EPUB metadata (title, authors, language, etc.). Throws an exception if called before `load()`.

```php
public function getSpine(): Spine
```

Returns the Spine object representing the reading order of content. Throws an exception if called before `load()`.

```php
public function getManifest(): Manifest
```

Returns the Manifest object listing every resource in the book. Throws an exception if called before `load()`.

```php
public function getContentManager(): ContentManager
```

Returns the ContentManager for adding/updating/deleting content files. Throws an exception if called before `load()`.

### Creating a Book

```php
public static function create(string $filePath, string $title, string $language = 'en', ?string $identifier = null): EpubFile
public function addChapter(string $title, string $body, ?string $path = null): ManifestItem
```

`create()` prepares a new EPUB 3 book (package document with title, language, identifier and `dcterms:modified`, plus a navigation document) and returns it opened, like `open()`. Nothing is written to `$filePath` until `save()`. Without `$identifier`, a random `urn:uuid:…` is used.

`addChapter()` writes an XHTML document with the given title and body markup, adds it to the manifest and the reading order, and appends it to the table of contents when the book has one. It works on any loaded book; by default chapters are stored as `text/chapter-N.xhtml` next to the OPF. The body is inserted as it is, so it must be well-formed XHTML.

A book needs at least one chapter to be valid.

```php
$epubFile = EpubFile::create('/path/to/new.epub', 'My Book', 'en');
$epubFile->getMetadata()->setAuthors(['Jane Doe']);
$epubFile->addChapter('Chapter One', '<h1>Chapter One</h1><p>It begins.</p>');
$epubFile->addChapter('Chapter Two', '<h1>Chapter Two</h1><p>It goes on.</p>');
$epubFile->save();
```

### Validating

```php
public function validate(): array
```

Checks the book, including unsaved changes, and returns a list of `PhpEpub\ValidationIssue` objects (`severity`, `code`, `message`, `location`); an empty list means no problem was found. It is a quick check before publishing, not a replacement for [EPUBCheck](https://www.w3.org/publishing/epubcheck/).

| Code | Severity | Problem |
|---|---|---|
| `METADATA_TITLE_MISSING`, `METADATA_LANGUAGE_MISSING`, `METADATA_IDENTIFIER_MISSING` | error | A required Dublin Core element is missing or empty |
| `METADATA_UNIQUE_IDENTIFIER` | error | `package@unique-identifier` does not name a `dc:identifier` |
| `METADATA_MODIFIED_MISSING` | error | An EPUB 3 package has no `dcterms:modified` |
| `DUPLICATE_ID` | error | An `id` is used more than once in the package document |
| `MANIFEST_HREF_OUTSIDE`, `MANIFEST_FILE_MISSING` | error | A manifest item points outside the book, or its file is missing |
| `FILE_NOT_IN_MANIFEST` | warning | A file of the publication is not listed in the manifest |
| `SPINE_EMPTY`, `SPINE_UNKNOWN_IDREF`, `SPINE_DUPLICATE_IDREF` | error | The reading order is empty, or refers to an unknown or repeated item |
| `SPINE_NOT_CONTENT` | warning | A spine item is not a content document and has no fallback |
| `NAV_MISSING` / `NCX_MISSING` | error | An EPUB 3 book has no navigation document / an EPUB 2 book has no NCX |
| `TOC_LINK_NOT_IN_MANIFEST` | error | The table of contents links to a file that is not in the manifest |

```php
foreach ($epubFile->validate() as $issue) {
    echo $issue, "\n"; // e.g. "error MANIFEST_FILE_MISSING (EPUB/images/gone.png): Manifest item "gone" has no file."
}
```

### Table of Contents

```php
public function getTableOfContents(): TableOfContents
```

Returns the [TableOfContents](table-of-contents.md) for reading and editing the EPUB 3 navigation document and the EPUB 2 NCX. It keeps the `EpubFile` (and its extracted files) alive, so `EpubFile::open($path)->getTableOfContents()->getEntries()` works. Throws an exception if called before `load()`.

### Cover Image

```php
public function getCoverImage(): ?ManifestItem
```

Returns the cover, looking in order at: the manifest item with the EPUB 3 `cover-image` property; the item named by the EPUB 2 `<meta name="cover">` (by id, or by href as some books write it); the EPUB 2 `<guide>` cover reference, which names either the image itself or a cover page whose first image (`<img>` or SVG `<image>`) is used. Returns `null` when the book has no cover. Read the bytes with `getContentManager()->getContent($cover->path)`.

```php
public function setCoverImage(string $imageData, string $mediaType, ?string $path = null, bool $deletePrevious = false): ManifestItem
```

Stores the image (by default as `images/cover.<ext>` next to the OPF file), adds it to the manifest and marks it as the cover: the `cover-image` property for EPUB 3 (removed from any previous cover) and `<meta name="cover">` for EPUB 2 compatibility. When `$path` is already in the manifest, that item is reused and its media type updated. The previous image file stays in the book unless `$deletePrevious` is `true`.

Throws if `$mediaType` is not an `image/…` type, or if JPEG, PNG, GIF or WebP data does not match it (for example PNG bytes declared as `image/jpeg`); other formats, such as SVG, are stored as declared. Nothing is written when it throws.

```php
public function removeCoverImage(bool $deleteFile = false): void
```

Unmarks the cover: removes the `cover-image` property, `<meta name="cover">` and `<guide>` cover references, so `getCoverImage()` returns `null` afterwards. With `$deleteFile`, the image is also deleted from the book (with its manifest item). A cover page in the reading order stays.

### Converting

```php
public function convert(ConverterInterface $converter, string $outputPath): void
```

Writes pending changes to the extracted book and converts it with the given adapter (`DompdfAdapter`, `TCPDFAdapter`, `CalibreAdapter`, …), so unsaved edits are included. Throws if called before `load()`.

### Cleanup

```php
public function cleanup(): void
```

Manually cleans up the temporary directory. Called automatically by `__destruct()`, but can be called explicitly to release resources earlier. Throws an `Exception` when the extracted files cannot all be deleted (e.g. a file still open on Windows); the path is kept so `cleanup()` can be retried, and the destructor ignores such failures. Afterwards the book is unloaded: `getMetadata()`, `getSpine()`, `getManifest()` and `getContentManager()` throw until `load()` is called again. A `load()` that fails also cleans up, so it never leaves a half-loaded book or its extracted files behind.

An `EpubFile` cannot be cloned (`clone` throws an `Exception`), because the copy would share, and later delete, the extracted book. Open the file again, or `save()` a copy and open that.

```php
public function getTempDir(): ?string
```

Returns the path to the temporary directory where EPUB contents are extracted. Returns null before `load()` is called.

## Usage Example

```php
use PhpEpub\EpubFile;

$epubFile = new EpubFile('/path/to/your.epub');
$epubFile->load();

// Access and modify metadata
$metadata = $epubFile->getMetadata();
echo $metadata->getTitle();
$metadata->setTitle('New Title');

// Access the spine (reading order)
$spine = $epubFile->getSpine();

// Manage content files
$content = $epubFile->getContentManager();

// Save changes (overwrites original); pending metadata edits are written first
$epubFile->save();

// Or save to a new file
$epubFile->save('/path/to/new.epub');

// Cleanup (optional - called automatically)
$epubFile->cleanup();
```

## Dependency Injection

The constructor accepts optional `ZipHandler` and `XmlParser` instances, making it easy to mock these dependencies in tests:

```php
use PhpEpub\EpubFile;
use PhpEpub\ZipHandler;
use PhpEpub\XmlParser;

// Using custom dependencies
$mockZipHandler = new MockZipHandler();
$mockXmlParser = new MockXmlParser();
$epubFile = new EpubFile('/path/to/file.epub', $mockZipHandler, $mockXmlParser);
```

## Error Handling

All exceptions extend `PhpEpub\Exception`. `load()` throws a `ZipException` when the archive cannot be read or exceeds the extraction limits, and an `InvalidEpubException` (or `XmlException`) when the book is invalid or references paths outside itself; see [Handling Untrusted EPUBs](advanced-usage.md#handling-untrusted-epubs). An `Exception` is also thrown in these cases:
- File not found
- Calling `save()`, `getMetadata()`, `getManifest()`, `getSpine()`, or `getContentManager()` before `load()`
- Temporary directory creation or cleanup failures

## File Structure

When loaded, the EPUB is extracted to a temporary directory with the following structure:

```
temp_dir/
├── mimetype
├── META-INF/
│   └── container.xml
└── EPUB/
    ├── package.opf
    ├── toc.ncx
    └── content/
        ├── chapter1.xhtml
        ├── chapter2.xhtml
        └── images/
```
