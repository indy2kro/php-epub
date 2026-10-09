# Converter

The `Converter` class converts an extracted EPUB into other formats by dispatching to format-specific adapters (PDF via Dompdf or TCPDF, MOBI/AZW3/… via Calibre).

## Key Methods

- **`__construct(string $epubDirectory, array $adapters)`**: Initializes the `Converter` with the directory containing the extracted EPUB and a map of format => `ConverterInterface` adapter. Throws an exception if the directory does not exist.

- **`convert(string $format, string $outputPath): void`**: Converts the EPUB with the adapter registered for `$format`. Throws an exception if the format is not supported or if the conversion fails.

Every adapter accepts the extracted directory. `CalibreAdapter` also accepts an `.epub` file; given a directory, it packages it into a temporary `.epub` first.

## Usage Example

```php
use PhpEpub\ConversionException;
use PhpEpub\Converter;
use PhpEpub\Converters\CalibreAdapter;
use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/book.epub');

$converter = new Converter($epubFile->getTempDir(), [
    'pdf' => new DompdfAdapter(),
    'mobi' => new CalibreAdapter(['calibre_path' => '/usr/bin/ebook-convert']),
]);

try {
    $converter->convert('pdf', '/path/to/output.pdf');
    $converter->convert('mobi', '/path/to/output.mobi');
} catch (ConversionException $e) {
    echo "Conversion failed: " . $e->getMessage();
}
```

## Limits and options for untrusted books

`PdfConversionOptions` is passed as the third constructor argument of `DompdfAdapter` and `TCPDFAdapter` (after the styles and the loader). It is immutable. An adapter built **without** options behaves exactly as before (nothing is limited, fixed-layout books are converted). Once you pass options, their defaults apply: the cover is included, there is no contents page, nothing is limited, and **fixed-layout books are refused** unless `allowFixedLayout` is `true`. Page size by name, margins, base font size and orientation stay styles of the adapters; only a custom page size is an option.

| Option | Default | |
| --- | --- | --- |
| `includeCover` | `true` | A cover image that no chapter shows becomes the first page. |
| `includeToc` | `false` | A generated contents page after the cover: the titles of `TableOfContents::getEntries()`, nested, escaped, each linking to its chapter. A book without a table of contents gets none. TCPDF adds a "Contents" bookmark. |
| `maxHtmlBytes` | none | The most chapter HTML (after sanitising), in bytes, summed over the book. |
| `maxImageBytes` | none | The most image data in bytes: each image file once, each inlined SVG or `data:` image every time it is used. |
| `maxChapters` | none | The most spine documents. |
| `timeBudgetSeconds` | none | Checked between chapters while reading and rendering, and before Dompdf starts rendering. Rendering one chapter (with Dompdf, the whole document) cannot be interrupted. |
| `allowFixedLayout` | `false` | A book that is `pre-paginated` as a whole, or for more than half of its spine documents (a `rendition:layout-*` property of a spine entry wins over the book), is refused: a flowing PDF cannot reproduce its pages. |
| `pageWidthMm`, `pageHeightMm` | none | A custom page size in mm, given together; replaces `paper_size` (the `orientation` style still applies). |

A limit equal to the size of the book is allowed, one more byte or chapter is not. A refusal throws a `ConversionException` whose code tells the reason: `CODE_FIXED_LAYOUT`, `CODE_BUDGET_EXCEEDED` (chapters, HTML or images) or `CODE_TIME_BUDGET_EXCEEDED`. The limits are checked while the book is read, before anything is rendered; invalid values (a negative limit, only one page dimension) throw an `Exception` from the `PdfConversionOptions` constructor.

```php
use PhpEpub\ConversionException;
use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\Converters\EpubDocumentLoader;
use PhpEpub\Converters\PdfConversionOptions;

$options = new PdfConversionOptions(
    includeToc: true,
    maxHtmlBytes: 8 * 1024 * 1024,
    maxImageBytes: 32 * 1024 * 1024,
    maxChapters: 300,
    timeBudgetSeconds: 45.0,
    pageWidthMm: 148.0,
    pageHeightMm: 210.0
);

$adapter = new DompdfAdapter(['font_size' => 11], new EpubDocumentLoader(), $options);

try {
    $adapter->convert('/path/to/extracted/epub', '/path/to/output.pdf');
} catch (ConversionException $e) {
    if ($e->getCode() === ConversionException::CODE_FIXED_LAYOUT) {
        echo "Fixed-layout books are not supported.";
    } else {
        echo "Refused or failed: " . $e->getMessage();
    }
}
```

Pass `new PdfConversionOptions(allowFixedLayout: true)` to convert a fixed-layout book anyway.

## How the PDF adapters read a book

`DompdfAdapter` and `TCPDFAdapter` render every XHTML document in the **spine**, in reading order, each starting on a new page, and take the PDF title and author from the EPUB metadata. A directory that only contains a `content.xhtml` file (the layout older versions required) is still accepted.

- **Cover:** when the book has a JPEG, PNG or GIF cover image (the EPUB 3 `cover-image` item, or the EPUB 2 `<meta name="cover">`) that the first chapter does not show, as in many EPUB 3 books, it becomes the first page, scaled to fit inside the margins. TCPDF adds a "Cover" bookmark for it.
- **Links between chapters** work inside the PDF: a link such as `chapter2.xhtml#note1` (or `#note1` within a chapter) jumps to that place, and a link to a chapter jumps to its first page. Each chapter and each element with an `id` get an extra, invisible anchor with an id unique across the book (`epub-c<chapter>-<id>`); the book's own ids are kept, so its CSS still applies. Links to remote sites are kept, and links to files that are not chapters are left as written.
The book's own CSS is kept: linked stylesheets (`<link rel="stylesheet">`, not alternate ones) and `<style>` blocks are collected once per book and applied after the adapter's defaults, so the book's styling wins. TCPDF and Dompdf each support only part of CSS, so complex layouts still render more simply than in a reader.

- **Encoding:** content documents are read in their own encoding: UTF-8, or UTF-16 and other encodings as given by the byte order mark or the XML declaration, and converted to UTF-8 before parsing (this needs the `mbstring` or `iconv` extension; without either, documents are read as UTF-8). `EpubFile::getCoverImage()` reads cover pages the same way.
- **Fonts and direction:** both adapters default to DejaVu Sans, which covers Latin, Greek, Cyrillic, Hebrew and Arabic; a `font` style always wins. Books that read right to left (`page-progression-direction="rtl"`, or a primary `dc:language` of `ar`, `he`, `fa`, `ur`, `yi`, `ps`, `sd`, `ug` or `dv`) are rendered right to left. See [TCPDFAdapter](converters/tcpdf-adapter.md) and [DompdfAdapter](converters/dompdf-adapter.md).
- **Errors:** an exception thrown by the renderer (Dompdf, TCPDF and its libraries) becomes a `ConversionException` with the original as its previous exception. Invalid styles are rejected by the adapters' constructors with an `Exception`.

Book content is treated as untrusted:

- Each document is parsed with an HTML parser (not pattern-matched), so unquoted or unusually written attributes are handled too.
- `<script>`, `<link>`, `<base>`, `<meta>`, `<iframe>`, `<object>`, `<embed>` and similar elements are removed, as are `on*` event attributes and `srcset`.
- Resource attributes (`src`, `xlink:href`, `poster`, `background`, `data`, and `href` on anything but links) are rewritten to files inside the book; absolute paths, `file://`, remote URLs, paths escaping the book and missing files are blanked. `data:` URIs are re-encoded as base64, and malformed ones are blanked.
- SVG images (files and `data:` URIs) are inlined as sanitised `data:` URIs. Scripts, `foreignObject`, processing instructions and event attributes are removed, every `href` and `url()` is confined to the book like the chapter's own references (references to the SVG's own elements, such as `#gradient`, are kept), and SVG files are not inlined into other SVGs. SVGs that are not well-formed or declare XML entities are blanked, and at most 16 MB of SVG is inlined per book. TCPDF reads an inlined SVG from a file in a private temporary directory, deleted after the conversion.
- CSS (stylesheets, `<style>` elements and `style` attributes) is sanitised: escapes are decoded so nothing is hidden behind them, `@import` and `image-set()` are removed, and every `url()` is rewritten to a file inside the book (relative to the stylesheet) or blanked. Stylesheets outside the book are ignored.
- Dompdf runs with remote resources, PHP and JavaScript disabled, and its file access limited (`chroot`) to the book directory.
- TCPDF 7 reads local files only from the book directory, the private temporary directory of its inlined SVGs, and its own packages (fonts); its defaults (the system temp dir, the working directory and the script directory) are not used, so books extracted anywhere keep their images. TCPDF 6 has no such allowlist.
