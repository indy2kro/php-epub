# Cleanup and Compression

`EpubFile::compress()` makes a book smaller and tidier: it removes files nothing uses, can strip scripts and remote references, drops unused fonts, recompresses images and repacks the archive at maximum deflate level. It is built from two reusable parts: `Cleanup` (the actions) and `ReferenceGraph` (which files a book really uses).

## Quick start

```php
use PhpEpub\Cleanup\CleanupPreset;
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/book.epub');

// Overwrites the file; pass a second argument to write somewhere else.
$report = $epubFile->compress(CleanupPreset::Balanced, '/path/to/smaller.epub');

echo $report->archiveBytesBefore . ' -> ' . $report->archiveBytesAfter . " bytes\n";
foreach ($report->actions as $action) {
    echo $action->name . ': ' . count($action->files) . ' files, ' . $action->bytesSaved() . " bytes saved\n";
}
```

## Presets

| Preset | What it does |
| --- | --- |
| `Light` | Removes unreferenced and stray files; repacks at maximum deflate level. |
| `Balanced` | Light, plus JPEG and PNG images scaled down to at most 1600 px and re-encoded at JPEG quality 80. |
| `Strong` | Light, plus images at most 1200 px at JPEG quality 65, and PNGs without transparency converted to JPEG when that is smaller. |

Image recompression needs the GD extension (`ext-gd`, listed under `suggest`); without it the images action is reported as skipped and everything else still runs.

## Options

`CleanupOptions::preset()` gives the combinations above; build your own with named arguments. Every action is off unless enabled.

```php
use PhpEpub\Cleanup\CleanupOptions;

$options = new CleanupOptions(
    removeUnreferenced: true,
    removeStrayFiles: true,
    stripScripts: true,
    removeRemoteReferences: true,
    removeUnusedFonts: true,
    recompressImages: true,
    maxImageWidth: 1600,
    maxImageHeight: 1600,
    jpegQuality: 75,
    convertOpaquePngToJpeg: false,
    maxDeflate: true,
);
```

- **`removeUnreferenced`**: removes manifest items (and their files) that nothing reachable refers to. The spine items, the navigation document, the NCX and the cover are never removed.
- **`removeStrayFiles`**: removes files that are not in the manifest and not needed. `mimetype`, `META-INF/`, the package document and files a reachable document refers to are kept; a book with several rootfiles (renditions) is left alone.
- **`stripScripts`**: removes `<script>` elements, inline event-handler attributes (`onclick`, ...) and `javascript:` URLs from XHTML and SVG documents, and updates the `scripted` manifest properties.
- **`removeRemoteReferences`**: removes images, media, stylesheets and other resources loaded from `http(s)` URLs (and CSS `@import` rules and `url()` declarations that point there), and updates the `remote-resources` properties. Links (`<a href>`) are kept.
- **`removeUnusedFonts`**: removes font files no reachable document or stylesheet refers to (and their `encryption.xml` entries).
- **`recompressImages`** with `maxImageWidth`, `maxImageHeight`, `jpegQuality` and `convertOpaquePngToJpeg`: images are never scaled up, and the original is kept when the result is not smaller. Animated PNG, GIF, SVG, CMYK JPEG and JPEG with an EXIF orientation are left alone. Converting a PNG renames the file and updates the manifest and every reference (`src`, `href`, `srcset`, CSS `url()`); it is not done when a document cannot be parsed, since its references could not be rewritten. The displayed size of an image is set by the document, so scaling an image down does not change the layout.
- **`maxDeflate`**: `compress()` writes the archive at deflate level 9 without directory entries.
- **`dryRun`**: nothing is changed or written; the report says what would happen.

The actions run in this order: scripts, remote references, unused fonts, unreferenced files, stray files, images.

## The report

`compress()` and `Cleanup::run()` return a `CleanupReport`:

```php
public function getAction(string $name): ?CleanupAction;
public function getFiles(): array;
public function bytesSaved(): int;
```

It has one `CleanupAction` per enabled action (`name` is one of the `CleanupAction` constants), with the `files` removed or changed, `bytesBefore` and `bytesAfter` (0 for removed files), and `skipped` with a `note`. `bytesBefore` and `bytesAfter` of the report are the size of all files of the book; `archiveBytesBefore` and `archiveBytesAfter` are the sizes of the `.epub` file (set by `compress()`).

## Using Cleanup directly

`Cleanup` works on the loaded book and leaves saving to you:

```php
use PhpEpub\Cleanup\Cleanup;
use PhpEpub\Cleanup\CleanupOptions;
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/book.epub');

$dryRun = (new Cleanup($epubFile))->run(new CleanupOptions(removeUnreferenced: true, dryRun: true));
echo count($dryRun->getFiles()) . " files would be removed\n";

(new Cleanup($epubFile))->run(new CleanupOptions(removeUnreferenced: true, stripScripts: true));
$epubFile->save('/path/to/cleaned.epub');
```

`Cleanup` refuses DRM-protected books (their content cannot be read) with an `Exception`.

## ReferenceGraph

`ReferenceGraph` answers "which files does this book use?". It starts from the roots (the spine, navigation document, NCX, cover and guide references) and follows `href`, `src`, `srcset`, `poster`, `data` and `xlink:href` attributes of XHTML, SVG and SMIL documents, and `url()`, `@import` and `image-set()` in stylesheets, `<style>` elements and `style` attributes. The fallback and media overlay of a reachable item are reachable too. References are resolved relative to the referring document, percent-decoded, and stripped of query strings and fragments; remote and out-of-book references are ignored.

Content it cannot read reliably (a document that is not well-formed, a script) is searched for the names of manifest files instead, so everything it might refer to counts as reachable. It never throws on bad content.

```php
use PhpEpub\Cleanup\ReferenceGraph;
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/book.epub');
$graph = ReferenceGraph::forBook($epubFile);

$analysis = $graph->analyze();
foreach ($analysis->unreachable as $path) {
    echo "Not used: {$path}\n";
}

// Start from any files, e.g. the chapters kept when splitting a book.
$part = $graph->analyzeFrom(['EPUB/text/ch1.xhtml', 'EPUB/text/ch2.xhtml']);
$files = $part->reachable;

// What one file refers to.
$images = $graph->referencesOf('EPUB/text/ch1.xhtml');
```

```php
public static function forBook(EpubFile $book): self;
public function defaultRoots(): array;
public function analyze(): ReferenceAnalysis;
public function analyzeFrom(array $roots): ReferenceAnalysis;
public function referencesOf(string $path): array;
```

`ReferenceAnalysis` has the sorted `reachable` and `unreachable` manifest paths, `references` (each reachable file's direct references), `unparsable` (reachable documents it had to search by name), `unmanifested` (files that exist and are referenced but are not in the manifest) and `isReachable()`.
