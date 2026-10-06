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

### Cover Image

```php
public function getCoverImage(): ?ManifestItem
```

Returns the cover: the manifest item with the EPUB 3 `cover-image` property, or else the item named by the EPUB 2 `<meta name="cover">`. Returns `null` when the book has no cover. Read the bytes with `getContentManager()->getContent($cover->path)`.

```php
public function setCoverImage(string $imageData, string $mediaType, ?string $path = null): ManifestItem
```

Stores the image (by default as `images/cover.<ext>` next to the OPF file), adds it to the manifest and marks it as the cover: the `cover-image` property for EPUB 3 (removed from any previous cover) and `<meta name="cover">` for EPUB 2 compatibility. The previous image file stays in the book. Throws if `$mediaType` is not an `image/…` type.

### Converting

```php
public function convert(ConverterInterface $converter, string $outputPath): void
```

Writes pending changes to the extracted book and converts it with the given adapter (`DompdfAdapter`, `TCPDFAdapter`, `CalibreAdapter`, …), so unsaved edits are included. Throws if called before `load()`.

### Cleanup

```php
public function cleanup(): void
```

Manually cleans up the temporary directory. Called automatically by `__destruct()`, but can be called explicitly to release resources earlier. Afterwards the book is unloaded: `getMetadata()`, `getSpine()`, `getManifest()` and `getContentManager()` throw until `load()` is called again. A `load()` that fails also cleans up, so it never leaves a half-loaded book or its extracted files behind.

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
