# Manifest

The `Manifest` class reads and edits the `<manifest>` section of the OPF file: the list of every resource in the book (documents, styles, images, fonts, navigation).

`EpubFile::load()` creates it; get it with `EpubFile::getManifest()`. `ContentManager` uses it to keep the manifest in sync when files are added or deleted.

## Paths and hrefs

Manifest `href`s are URLs relative to the OPF file (for example `text/chapter%201.xhtml` next to `EPUB/package.opf`). The `Manifest` translates them to **paths relative to the book root** (`EPUB/text/chapter 1.xhtml`), which is what `ContentManager` expects. Hrefs that would escape the book are rejected; remote resources (EPUB 3 allows e.g. streamed audio) get an empty path.

## Key Methods

- **`__construct(SimpleXMLElement $opfXml, string $opfPath)`**: `$opfPath` is the OPF location relative to the book root, as returned by `Parser::parse()`. Throws `InvalidEpubException` if the package has no manifest.

- **`getItems(): array`**: All items as `ManifestItem` objects with `id`, `href`, `path`, `mediaType` and `properties`.

- **`get(string $id): ?ManifestItem`** and **`findByPath(string $path): ?ManifestItem`**: Look up one item.

- **`add(string $path, ?string $mediaType = null, ?string $id = null): ManifestItem`**: Adds a file. The media type is guessed from the extension and the id is derived from the file name when not given. Throws if the path is already listed or the id is taken.

- **`remove(string $id): void`**: Removes an item. Spine entries are not touched; use `Spine::remove()` (or `ContentManager::deleteContent()`, which does both).

- **`pathToHref(string $path): string`** and **`hrefToPath(string $href): string`**: Convert between the two forms.

- **`getOpfPath(): string`**: The OPF location relative to the book root.

## Usage Example

```php
use PhpEpub\EpubFile;

$epubFile = new EpubFile('/path/to/your.epub');
$epubFile->load();

$manifest = $epubFile->getManifest();

foreach ($manifest->getItems() as $item) {
    echo "{$item->id}: {$item->path} ({$item->mediaType})\n";
}

// Register a font that was copied into the book by other means
$manifest->add('EPUB/fonts/Literata.woff2');
$epubFile->save();
```
