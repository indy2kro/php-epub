# Manifest

The `Manifest` class reads and edits the `<manifest>` section of the OPF file: the list of every resource in the book (documents, styles, images, fonts, navigation).

`EpubFile::load()` creates it; get it with `EpubFile::getManifest()`. `ContentManager` uses it to keep the manifest in sync when files are added or deleted.

## Paths and hrefs

Manifest `href`s are URLs relative to the OPF file (for example `text/chapter%201.xhtml` next to `EPUB/package.opf`). The `Manifest` translates them to **paths relative to the book root** (`EPUB/text/chapter 1.xhtml`), which is what `ContentManager` expects. Hrefs that would escape the book are rejected; remote resources (EPUB 3 allows e.g. streamed audio) get an empty path.

## Key Methods

- **`__construct(SimpleXMLElement $opfXml, string $opfPath)`**: `$opfPath` is the OPF location relative to the book root, as returned by `Parser::parse()`. Throws `InvalidEpubException` if the package has no manifest.

- **`getItems(): array`**: All items as `ManifestItem` objects with `id`, `href`, `path`, `mediaType` and `properties`. `path` is empty for remote resources and for hrefs that point outside the book; such items are listed but have no file.

- **`get(string $id): ?ManifestItem`** and **`findByPath(string $path): ?ManifestItem`**: Look up one item.

- **`add(string $path, ?string $mediaType = null, ?string $id = null): ManifestItem`**: Adds a file. The media type is guessed from the extension (XHTML, CSS, JavaScript, NCX, SMIL, PLS, XML, JSON, text, WebVTT, the common image, font, audio and video formats; `application/octet-stream` otherwise) and the id is derived from the file name when not given. Throws if the path is already listed, the id is already used anywhere in the package, or the media type or id is not valid UTF-8 XML text.

- **`remove(string $id): void`**: Removes an item and the package references to it (EPUB 2 cover meta, refinements, `spine@toc`, `fallback`/`media-overlay` of other items, `<guide>` references to its file). Spine entries are not touched; use `Spine::remove()` (or `ContentManager::deleteContent()`, which does both).

- **`pathToHref(string $path): string`** and **`hrefToPath(string $href): string`**: Convert between the two forms.

- **`addProperty(string $id, string $property): void`** and **`removeProperty(string $id, string $property): void`**: Add or remove an EPUB 3 property token (e.g. `cover-image`, `nav`) on an item; other tokens are kept.
- **`isEpub3(): bool`**: Whether the package is EPUB 3 (version 3.x), whose items carry properties.

- **`setMediaType(string $id, string $mediaType): void`**: Changes an item's media type, e.g. after its file was replaced with another format.

- **`moveItem(string $id, string $path): void`**: Points an item at another file (path relative to the book root), keeping its id, and updates `<guide>` references to its old file. The file itself is not moved; `ContentManager::moveContent()` moves both. Throws if no item has the id or another item already has the path.

- **`findByHref(string $href): ?ManifestItem`**: Looks up an item by an href relative to the OPF file (fragments ignored); `null` when nothing matches or the href points outside the book.

- **`getGuidePath(string $type): ?string`**: The book-root path of the EPUB 2 `<guide>` reference of a type such as `cover` or `toc` (matched case-insensitively), or `null`.

- **`removeGuideReferences(string $type): void`**: Removes the EPUB 2 `<guide>` references of a type (compared case-insensitively), and the guide when none is left.

- **`getGuideReferences(): array`** and **`setGuideReferences(array $references): void`**: The EPUB 2 `<guide>` as `Landmark` objects that carry the guide's own types (`cover`, `text`, `title-page`, ...); references that point outside the book are left out. Setting replaces the guide (created after the spine when missing) and `[]` removes it. Use `TableOfContents::getLandmarks()` and `setLandmarks()` to work with EPUB 3 types and the navigation document at the same time.

- **`getMediaOverlay(string $id): ?string`** and **`setMediaOverlay(string $id, ?string $overlayId): void`**: The SMIL item (`application/smil+xml`) that narrates an XHTML or SVG item (its `media-overlay` attribute); `null` removes it. EPUB 3 only. See [EPUB 3 Features](epub3-features.md#media-overlays).

- **`getOpfPath(): string`**: The OPF location relative to the book root.

Lookups (`get()`, `findByPath()`, `getItems()`) use an index built on first use, so adding thousands of files to a large book stays fast.

## Usage Example

```php
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/your.epub');

$manifest = $epubFile->getManifest();

foreach ($manifest->getItems() as $item) {
    echo "{$item->id}: {$item->path} ({$item->mediaType})\n";
}

// Register a font that was copied into the book by other means
$manifest->add('EPUB/fonts/Literata.woff2');
$epubFile->save();
```
