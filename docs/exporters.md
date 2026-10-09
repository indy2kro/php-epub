# Exporters

Three adapters export a book as text, a single HTML file or Markdown. They implement `ConverterInterface` like the PDF adapters, so they fit `Converter` and `EpubFile::convert()`, and each also returns its result as a string for web tools that do not want a file on disk.

| Adapter | Output | In memory | To a file |
|---|---|---|---|
| `TextAdapter` | plain UTF-8 text | `toString($directory)` | `convert($directory, $path)` |
| `HtmlAdapter` | one self-contained HTML document | `toString($directory)` | `convert($directory, $path)` |
| `MarkdownAdapter` | CommonMark with GFM tables, plus the images | `export($directory)` | `convert($directory, $path)` (and `images/` beside it) |

All three read the book the way the PDF adapters do (see [Converter](converter.md)): the spine documents in reading order, parsed with an HTML parser and made safe by `EpubDocumentLoader`, so a hostile book cannot smuggle markup, script or remote references into the output. A DRM-protected book is refused with a `ConversionException`.

```php
use PhpEpub\Converters\HtmlAdapter;
use PhpEpub\Converters\MarkdownAdapter;
use PhpEpub\Converters\TextAdapter;
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/book.epub');

// Includes edits that have not been saved yet.
$epubFile->convert(new TextAdapter(), '/path/to/book.txt');
$epubFile->convert(new HtmlAdapter(), '/path/to/book.html');
$epubFile->convert(new MarkdownAdapter(), '/path/to/book.md');   // also writes /path/to/images/

// Or in memory, from the extracted directory.
$text = (new TextAdapter())->toString($epubFile->getTempDir());
$html = (new HtmlAdapter())->toString($epubFile->getTempDir());
```

## Text

`TextAdapter` writes the book title and authors, then each chapter of the reading order under a heading, with a blank line between paragraphs. A chapter's heading is its title in the table of contents, else its first heading; when the chapter's first line repeats it, the line is shown once.

```php
public function __construct(
    bool $includeNonLinear = false,
    bool $headings = true,
    EpubDocumentLoader $loader = new EpubDocumentLoader()
);
public function toString(string $epubDirectory): string;
public function convert(string $epubDirectory, string $outputPath): void;
```

- `includeNonLinear` also exports the auxiliary spine items (`linear="no"`, usually notes). They are skipped by default.
- `headings: false` leaves out the title block and the added chapter headings (the book's own headings stay, as they are part of the text).

## HTML

`HtmlAdapter` writes one self-contained HTML document: a title block, a table of contents when the book has several chapters, and every chapter in a `<section class="epub-chapter">`.

```php
public function __construct(
    int $maxInlinedBytes = 33554432,
    bool $includeNonLinear = true,
    bool $includeToc = true,
    EpubDocumentLoader $loader = new EpubDocumentLoader()
);
public function toString(string $epubDirectory): string;
public function convert(string $epubDirectory, string $outputPath): void;
```

- **CSS** from the book (linked stylesheets and `<style>` blocks) is concatenated into one `<style>` element after a small default. Every selector is scoped to the container `.epub-book`, so the book cannot restyle the page around it; `body`, `html` and `:root` rules style the container itself. `@font-face`, `@keyframes` and `@page` blocks are kept, and other at-rules are dropped.
- **Images and fonts** of the book are inlined as `data:` URIs (JPEG, PNG, GIF, WebP and sanitised SVG), up to `maxInlinedBytes` in total. An image past the budget is replaced by its alt text in brackets.
- **Links** between chapters, and to footnotes, point at anchors inside the document. A link whose target is not in the document (for example a note that was left out with `includeNonLinear: false`) loses its `href`. `includeNonLinear` defaults to `true` here so links to notes keep working.

### What is removed

The output is meant to be shown on a page, so it is reduced to an allowlist:

- Only text, list, table, heading and image elements remain, with `id`, `class`, `lang`, `dir`, `title`, `style` and the few attributes those elements need (`href` on links, `src`/`alt`/`width`/`height` on images, `colspan`/`rowspan`/`scope`/`headers` on cells, `start`/`type` on lists). Other elements that only wrap content (`font`, `center`, custom elements, `form`) are replaced by their children.
- `script`, `style` (in the body, whose rules move into the scoped stylesheet), `link`, `meta`, `base`, `iframe`, `object`, `embed`, `form` controls, `audio`, `video`, `source`, `svg` (an SVG that only shows an `<image>`, the usual cover page, becomes an `<img>`), `math` and `canvas` are removed together with their content.
- No event-handler attribute (`on*`), `srcset` or `epub:*` attribute survives.
- Links keep only `http`, `https`, `mailto` and in-document (`#`) targets, so `javascript:`, `data:`, `file:`, relative and protocol-relative links lose their `href`. External links get `rel="noopener noreferrer"`.
- Nothing loads from a remote URL: images must be files of the book (anything else becomes alt text), and in CSS every `url()` is either an inlined image or font or becomes `none`; `@import`, `image-set()`, `expression()`, `behavior` and `-moz-binding` are removed, and CSS escapes are decoded first so nothing hides behind them.
- A `Content-Security-Policy` `<meta>` (`default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:`) repeats this for browsers.

## Markdown

`MarkdownAdapter` writes the title, then every chapter. `export()` returns a `MarkdownExport` with the text and a map of image path to bytes; `MarkdownExport::writeTo()` writes `book.md` and an `images/` folder into a directory.

```php
use PhpEpub\Converters\MarkdownAdapter;

$export = (new MarkdownAdapter())->export($epubFile->getTempDir());

echo $export->markdown;
foreach ($export->images as $path => $bytes) {
    // $path is relative to the Markdown file, e.g. "images/cover.jpg".
}

// book.md and images/ in a directory (created when missing).
$export->writeTo('/path/to/output');
```

```php
public function __construct(
    bool $includeNonLinear = false,
    bool $includeHeader = true,
    int $maxImageBytes = 67108864,
    int $maxImageSize = 16777216,
    EpubDocumentLoader $loader = new EpubDocumentLoader()
);
public function export(string $epubDirectory): MarkdownExport;
public function convert(string $epubDirectory, string $outputPath): void;
```

```php
public function __construct(string $markdown, array $images = []);
public function writeTo(string $directory, string $fileName = 'book.md'): void;
```

What is converted:

- Headings, paragraphs, emphasis, strong text, strikethrough, inline code and code blocks (fenced, with the language of a `language-xxx` class), block quotes, nested lists (ordered lists keep their start number), links, hard line breaks and images.
- Tables become GFM tables when they are simple: no `colspan`/`rowspan`, no lists, quotes or nested tables in cells, and the same number of cells in every row (the first row is the header). Other tables become plain text, one row per line with the cells separated by ` | `.
- Images are written with a relative path (`images/<name>`, named after the source file, numbered when a name repeats). Only JPEG, PNG, GIF, WebP and sanitised SVG files of the book are exported, up to `maxImageSize` each and `maxImageBytes` in total; any other image is replaced by its alt text.
- A chapter without a heading of its own gets one from its table-of-contents title.

What it never contains: raw HTML (text is escaped so it cannot form markup, which also means characters such as `*`, `_` and `<` appear with a backslash), `javascript:` or other non-`http(s)`/`mailto` links, and remote images. Links inside the book become plain text, because Markdown has no portable anchors.

`writeTo()` only writes `images/<name>` paths made of letters, digits, `.`, `_` and `-`, and refuses a `MarkdownExport` holding any other path with a `ConversionException`, so a hand-built export cannot write outside the directory.
