# Spine

The `Spine` class in the PHP EPUB Processor library is responsible for managing the reading order of the content within an EPUB file.
It parses the spine section of the OPF file to determine the sequence in which the content should be presented to the reader.

## Key Methods

- **`__construct(SimpleXMLElement $opfXml)`**: Initializes the `Spine` object with the OPF XML data. It parses the spine section to extract the reading order of the content items.

- **`get(): array`**: Returns an array of item IDs that represent the reading order of the EPUB content. Each ID corresponds to a content item defined in the manifest section of the OPF file.

## Usage Example

```php
use PhpEpub\EpubFile;

$epubFilePath = '/path/to/your.epub';

$epubFile = new EpubFile($epubFilePath);
$epubFile->load();

$spine = $epubFile->getSpine();
$readingOrder = $spine->get();

foreach ($readingOrder as $itemId) {
    echo "Content item ID in reading order: $itemId\n";
}
```
