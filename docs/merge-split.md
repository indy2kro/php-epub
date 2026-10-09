# Merging and Splitting Books

`Merge\Merger` combines several books (the volumes of a series, the chapters of a serial) into one EPUB 3 book. `Split\Splitter` does the opposite and cuts a book into parts. Both only read the books they are given: nothing in them changes, and their files on disk are not touched (unsaved edits are included). Both treat the books as untrusted, like the rest of the library, and both refuse DRM-protected books.

## Merging

```php
use PhpEpub\EpubFile;
use PhpEpub\Merge\MergeOptions;
use PhpEpub\Merge\Merger;

$books = [
    EpubFile::open('/path/to/volume-1.epub'),
    EpubFile::open('/path/to/volume-2.epub'),
    EpubFile::open('/path/to/volume-3.epub'),
];

$report = (new Merger())->merge($books, new MergeOptions(title: 'The Complete Series'), '/path/to/series.epub');

echo $report->books . ' books, ' . $report->files . ' files, ' . $report->deduplicated . " duplicates left out\n";
```

```php
public function merge(array $books, MergeOptions $options, string $outputPath): MergeReport;
```

### Options

All arguments of `MergeOptions` are optional.

| Argument | Default | Meaning |
| --- | --- | --- |
| `title` | first book's title | Title of the merged book. |
| `authors` | first book's authors | List of author names. |
| `language` | first book's language | Main language. The other books' languages follow it as additional `dc:language` values. |
| `identifier` | new `urn:uuid:…` | Unique identifier. |
| `coverImage`, `coverMediaType` | first book's cover | Image bytes (and media type, `image/jpeg` by default) for another cover. |
| `oneSectionPerBook` | `true` | One table of contents entry per book (titled with the book's title, linking to its first document) with the book's own entries below it; `false` puts the books' entries one after the other. |
| `deduplicate` | `true` | Keep one copy of byte-identical stylesheets, fonts and raster images. |
| `maxBooks` | `10` | The most books accepted (at least 2). |
| `maxTotalBytes` | 24 MiB | The most bytes accepted: the size of the books' extracted files together. |
| `maxTotalFiles` | `5000` | The most files accepted: the number of files in the books' extracted directories together. |
| `clock` | now | A closure returning the `DateTimeInterface` to record as `dcterms:modified`. |

Fewer than two books, more than `maxBooks`, or more than `maxTotalBytes` or `maxTotalFiles` throw a `PhpEpub\Exception`; so does a DRM-protected book.

### What the merged book looks like

- **Layout**: every book keeps its files in its own directory (`EPUB/book-01/`, `EPUB/book-02/`, …) with the layout it had, so the references between its files (XHTML, CSS `url()` and `@import`, SVG, `srcset`, media overlays) stay valid without rewriting. Manifest ids get the same prefix (`b01-chapter1`) and are unique.
- **Reading order**: book order, then each book's own order. `linear="no"` and spine item properties (page spreads, rendition overrides) are kept, and so is the first book's page-progression direction.
- **Navigation**: the sources' navigation documents and NCX files are not copied (a navigation document that is also in a book's reading order stays as an ordinary document; links in the books' documents to a navigation document that is not copied become plain text). The merged book gets a new navigation document and NCX built from the books' table of contents (see `oneSectionPerBook`), and the first book's cover landmark. Page lists and the EPUB 2 `<guide>` are dropped. Remote resources (items with a URL instead of a file) are listed again with their new ids, `fallback` attributes follow the new ids, media overlays and their durations follow their documents, and the book's total media duration is the sum of the overlays' (left out when one overlay has no duration).
- **EPUB 2 books** are upgraded as needed: the package is EPUB 3, the properties that XHTML documents need (`svg`, `mathml`, `scripted`, `remote-resources`) are set, and an XHTML 1.0 or 1.1 doctype becomes `<!DOCTYPE html>` (named entities such as `&nbsp;` become numeric references). Other XHTML 1.1 constructs that EPUB 3 does not allow are not rewritten.
- **Metadata**: from the options or the first book. The access modes, features and hazards of all books are combined (a `none` or `unknown` hazard only when no book names a real one) and the accessibility summaries follow each other.
- **Rendition**: when every book is pre-paginated the merged book is too. When the books mix reflowable and pre-paginated layouts, the spine items of the pre-paginated books carry a `rendition:layout-pre-paginated` override instead.
- **Cover**: the first book's cover (EPUB 3 `cover-image` and EPUB 2 `<meta name="cover">`), or `coverImage`.

### Deduplication

Byte-identical stylesheets, fonts and raster images of different books are stored once, and the references to the copies that are left out are rewritten (so they point into the first book's directory). A stylesheet with `url()` or `@import` is never merged, because its references are relative to its own directory and identical text can mean different files. SVG images are never merged either. A file that a document mentions which cannot be rewritten (not well-formed XHTML, a script) is kept as it is.

### Fonts

Font obfuscation depends on the book's unique identifier, which is new in the merged book. Obfuscated fonts (IDPF or Adobe) are therefore read de-obfuscated with their own book's key and written obfuscated again with the **IDPF algorithm and the merged book's key**, with entries in the merged book's `META-INF/encryption.xml`. Fonts that were plain stay plain. Two books with the same font but different identifiers still share one copy, because identical fonts are compared de-obfuscated.

## Splitting

```php
use PhpEpub\EpubFile;
use PhpEpub\Split\SplitPlan;
use PhpEpub\Split\Splitter;

$book = EpubFile::open('/path/to/book.epub');

// One part per top-level table of contents entry.
$paths = (new Splitter())->split($book, SplitPlan::byToc(), '/path/to/parts');

// Other plans:
$plan = SplitPlan::byToc(2);                          // entries of the second level
$plan = SplitPlan::everySpineItems(10);               // ten reading order items per part
$plan = SplitPlan::bySpineRanges([[0, 4], [5, 9]]);   // explicit zero-based, inclusive ranges
$plan = SplitPlan::byMaxBytes(5_000_000);             // about 5 MB per part

$plan = SplitPlan::everySpineItems(10)
    ->withTitlePattern('{title}, volume {n}/{total}')
    ->withFilePrefix('volume');
```

```php
public function split(EpubFile $book, SplitPlan $plan, string $outputDirectory): array;

public static function byToc(int $level = 1): self;
public static function everySpineItems(int $count): self;
public static function bySpineRanges(array $ranges): self;
public static function byMaxBytes(int $bytes): self;
public function withTitlePattern(string $pattern): self;
public function withFilePrefix(string $prefix): self;
public function withClock(\Closure $clock): self;
public function withMaxParts(int $maxParts): self;
```

`split()` creates the directory when needed, writes `part-01.epub`, `part-02.epub`, … (the prefix is configurable) and returns their paths in order.

### Plans

- **`byToc($level)`**: each entry of that table of contents level starts a part, which runs to the next entry's first reading order item. Items before the first entry go with the first part. Entries that do not point into the reading order are skipped; a book without such entries cannot be split this way (`Exception`).
- **`everySpineItems($count)`**: chunks of the reading order; the last may be shorter.
- **`bySpineRanges($ranges)`**: `[first, last]` pairs of zero-based, inclusive positions in the reading order, in order and without overlap. Items outside every range are left out. A range beyond the reading order throws.
- **`byMaxBytes($bytes)`**: items are added to a part while its files stay within the limit. The size counts the documents and the images, stylesheets and fonts they use (each once per part), uncompressed. An item that alone exceeds the limit gets a part of its own, so the limit is approximate.

With `withMaxParts()` (50 by default) a plan that would produce more parts is refused before anything is written, and if writing a part fails the parts already written are deleted. A part is never empty. A book that ends up in a single part is still written, with its title unchanged.

### What a part looks like

- **Content**: the part's reading order items and what they need, found with `ReferenceGraph::analyzeFrom()`: images, stylesheets, fonts, fallbacks and media overlays, plus the navigation document, the NCX and the cover image. Everything else, including files nothing uses, is removed, together with the `META-INF/encryption.xml` entries of removed fonts.
- **Navigation**: the navigation document, the NCX and the landmarks keep only the entries that point into the part. An entry whose own document is in another part stays as an unlinked heading when some of its children are in this one; otherwise it is dropped. A part without any entry gets one for its first document. Page lists are dropped, because their pages are spread over the whole book. Other navs (lists of figures or tables and the like) keep only the entries that point into the part, and a nav left empty is removed.
- **Links to other parts**: an `<a>` or `<area>` that points to a document of another part is replaced by its content (the link text stays, the link goes), and a `<link>` element to it is removed. Links within the part, to resources and to fragments of the same document are kept. A document that is not well-formed is left as it is.
- **Metadata**: cloned from the book, with a new `urn:uuid:` unique identifier for each part (other identifiers, such as an ISBN, are dropped because they identify the whole book), the title from the pattern and a new `dcterms:modified`. Obfuscated fonts are re-keyed to the part's identifier.

The title pattern replaces `{title}` (the book's title), `{n}` (the part's number) and `{total}` (the number of parts); the default is `{title} (Part {n} of {total})`.
