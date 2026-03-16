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

Initializes the EpubFile with the path to an EPUB file. The optional `$zipHandler` and `$xmlParser` parameters allow dependency injection for testing.

### Loading and Saving

```php
public function load(): void
```

Loads the EPUB file:
- Extracts ZIP contents to a temporary directory
- Parses `container.xml` to locate the OPF file
- Parses the OPF file to extract metadata and spine
- Initializes the ContentManager for file operations

Throws an exception if the file cannot be opened or the EPUB structure is invalid.

```php
public function save(?string $filePath = null): void
```

Saves the modified EPUB back to disk. If `$filePath` is null, overwrites the original file. Throws an exception if called before `load()`.

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
public function getContentManager(): ContentManager
```

Returns the ContentManager for adding/updating/deleting content files. Throws an exception if called before `load()`.

### Cleanup

```php
public function cleanup(): void
```

Manually cleans up the temporary directory. Called automatically by `__destruct()`, but can be called explicitly to release resources earlier.

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
$metadata->save();

// Access the spine (reading order)
$spine = $epubFile->getSpine();

// Manage content files
$content = $epubFile->getContentManager();

// Save changes (overwrites original)
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

The class throws `Exception` in the following cases:
- File not found or cannot be opened
- Invalid EPUB structure
- Calling `load()`, `save()`, `getMetadata()`, `getSpine()`, or `getContentManager()` before `load()`
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
