# Metadata

The `Metadata` class in the PHP EPUB Processor library is responsible for managing the metadata of an EPUB file.
This includes operations such as reading and updating the title, authors, language, and other metadata fields.

## Key Methods

- **`__construct(SimpleXMLElement $opfXml, string $opfFilePath)`**: Initializes the `Metadata` object with the OPF XML data and its file path. It also identifies the Dublin Core (dc) namespace used for metadata.

- **`save(): void`**: Saves the updated OPF file back to disk. Throws an exception if the file cannot be saved.

- **`getOpfFilePath(): string`**: Returns the file path of the OPF file.

## Traits

The `Metadata` class uses several traits to interact with specific metadata fields:

- **`InteractsWithTitle`**: Methods for getting and setting the title.
- **`InteractsWithDescription`**: Methods for managing the description.
- **`InteractsWithDate`**: Methods for handling date-related metadata.
- **`InteractsWithAuthors`**: Methods for managing author information.
- **`InteractsWithPublisher`**: Methods for handling publisher details.
- **`InteractsWithLanguage`**: Methods for managing language metadata.
- **`InteractsWithSubject`**: Methods for handling subject-related metadata.
- **`InteractsWithIdentifier`**: Methods for managing identifiers like ISBN.

## Usage Example

```php
use PhpEpub\EpubFile;

$epubFilePath = '/path/to/your.epub';

$epubFile = new EpubFile($epubFilePath);
$epubFile->load();

$metadata = $epubFile->getMetadata();
$title = $metadata->getTitle();
$metadata->setTitle('New Title');
$metadata->save();

$epubFile->save();
```
