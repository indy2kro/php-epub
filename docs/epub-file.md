# EpubFile

The `EpubFile` class is a core component of the PHP EPUB Processor library.
It provides functionality to load, manipulate, and save EPUB files.
This class handles the extraction of EPUB contents, parsing of metadata, and management of the EPUB file structure.

## Key Methods

- **`__construct(string $filePath)`**: Initializes the `EpubFile` object with the path to the EPUB file.

- **`load(): void`**: Loads the EPUB file, extracting its contents to a temporary directory and parsing its metadata and spine.

- **`save(?string $filePath = null): void`**: Saves the current state of the EPUB file back to disk. If no file path is provided, it overwrites the original file.

- **`getTempDir(): ?string`**: Returns the path to the temporary directory where the EPUB contents are extracted.

- **`getMetadata(): ?Metadata`**: Retrieves the metadata object associated with the EPUB file.

- **`getSpine(): ?Spine`**: Retrieves the spine object, which represents the reading order of the EPUB content.

## Usage Example

```php
use PhpEpub\EpubFile;

$epubFile = new EpubFile('/path/to/your.epub');
$epubFile->load();

// make changes

// Save changes
$epubFile->save();
```

## Destruction

The `__destruct()` method ensures that the temporary directory used during processing is cleaned up, preventing any leftover files from occupying disk space.