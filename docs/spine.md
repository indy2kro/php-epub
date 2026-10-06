# Spine

The `Spine` class manages the reading order of the content within an EPUB file: the `<spine>` section of the OPF file.

## Key Methods

- **`__construct(SimpleXMLElement $opfXml, ?Manifest $manifest = null)`**: Parses the spine. The manifest is optional; it is needed to resolve items in `getItems()` and to validate `add()`.

- **`get(): array`**: Returns the manifest item ids (idrefs) in reading order.

- **`getItems(): array`**: Returns `SpineItem` objects in reading order. Each has `idref`, `linear` (`false` for auxiliary content marked `linear="no"`, such as notes) and `item`, the referenced `ManifestItem` (with its `path` relative to the book root), or `null` without a manifest.

- **`contains(string $idref): bool`**: Whether an item is in the reading order.

- **`add(string $idref, ?int $position = null, bool $linear = true): void`**: Adds a manifest item to the reading order, at a zero-based position or at the end. Throws if the item is not in the manifest or is already in the spine.

- **`remove(string $idref): void`**: Removes an item from the reading order (the manifest is not touched). Throws if it is not in the spine.

- **`move(string $idref, int $position): void`**: Moves an item to a new zero-based position.

Changes are made to the OPF in memory; `EpubFile::save()` writes them (and refreshes the EPUB 3 modified date). Other `<itemref>` attributes, such as `id` and `properties`, are kept.

## Usage Example

```php
use PhpEpub\EpubFile;

$epubFile = new EpubFile('/path/to/your.epub');
$epubFile->load();

$spine = $epubFile->getSpine();

foreach ($spine->getItems() as $spineItem) {
    $type = $spineItem->linear ? 'main' : 'auxiliary';
    echo "{$spineItem->idref} ({$type}): {$spineItem->item?->path}\n";
}

// Show the notes right after the first chapter, then save.
$spine->move('notes', 1);
$epubFile->save();
```
