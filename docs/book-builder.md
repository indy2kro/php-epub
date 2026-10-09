# Book builder

`BookBuilder` makes a new EPUB 3 (with an NCX for EPUB 2 reading systems) from Markdown, plain text, HTML or a list of images, for tools such as "create an EPUB from my notes" or "turn these scans into a comic book". The input is treated as untrusted.

```php
use PhpEpub\Build\BookBuilder;
use PhpEpub\Build\BookOptions;

$builder = new BookBuilder(new BookOptions(
    title: 'My Book',
    authors: ['Jane Doe'],
    language: 'en',
    description: 'Notes on things.',
    coverImage: file_get_contents('/path/to/cover.jpg'),
    images: ['images/figure.png' => file_get_contents('/path/to/figure.png')],
    splitLevel: 2
));

$book = $builder->fromMarkdown(file_get_contents('/path/to/book.md'));

$book->save('/path/to/book.epub');   // or $book->toString() for a download, $book->open() to edit it further
echo $book->chapterCount . " chapters\n";
foreach ($book->warnings as $warning) {
    echo $warning . "\n";            // images that were not supplied or not usable
}
```

## API

```php
public function __construct(BookOptions $options = new BookOptions(), ?XmlParser $xmlParser = null);
public function fromMarkdown(string $markdown): BuiltBook;
public function fromText(string $text): BuiltBook;
public function fromHtml(string $html): BuiltBook;
public function fromImages(array $images, bool $naturalSort = false): BuiltBook;
```

Every method throws `PhpEpub\BuildException` (a subclass of `PhpEpub\Exception`) when an option is invalid, there is nothing to build, or a limit is exceeded.

`BuiltBook` holds the result:

```php
public string $epub;          // the EPUB file's bytes
public int $chapterCount;     // chapters (or comic pages) in the reading order
public array $warnings;       // list<string>: what was left out
public function toString(): string;
public function save(string $path): void;
public function open(): EpubFile;
```

## Options

`BookOptions` takes named arguments:

| Option | Default | Meaning |
|---|---|---|
| `title` | `'Untitled'` | The book title (required, not empty). |
| `authors` | `[]` | List of author names. |
| `language` | `'en'` | A BCP 47 tag such as `en` or `pt-BR`. |
| `identifier` | random `urn:uuid:...` | The unique identifier. |
| `description`, `publisher` | `''` | Written when not empty. |
| `date` | none | `YYYY`, `YYYY-MM`, `YYYY-MM-DD`, a `DateTimeInterface`, or anything `DateTimeImmutable` reads (written as `YYYY-MM-DD`). |
| `coverImage` | none | Cover bytes (JPEG, PNG, GIF or WebP); adds a cover page. `fromImages()` uses its first image as the cover instead. |
| `css` | `''` | Extra CSS after a small default. Every `url()` and `@import` is removed. |
| `images` | `[]` | Images the content refers to: path as written in the content (or just the file name) => bytes. |
| `splitLevel` | `1` | Chapters start at headings up to this level: `1` for `h1`, `2` for `h1` and `h2`, up to `6`. |
| `chapterPattern` | `'/^(Chapter\|CHAPTER)\s+\w+/'` | Marks a chapter heading line in `fromText()`. |
| `direction` | `'ltr'` | `ltr` or `rtl`: the page progression direction (right-to-left manga, Arabic, Hebrew). |
| `limits` | `new BuildLimits()` | See [Limits](#limits). |

## Markdown

`fromMarkdown()` reads headings (ATX and setext), paragraphs, emphasis, strong text, `~~strikethrough~~`, code spans, fenced and indented code, block quotes, nested lists, thematic breaks, links, images, `<autolinks>`, hard line breaks and GFM tables. Headings get an id from their text (`# Chapter One` is `chapter-one`, repeats are numbered) or the one written as `{#id}`, so `[see](#chapter-one)` works, including across chapters.

Raw HTML in Markdown is **not** passed through: `<` and `&` are escaped, so `<script>` shows up as text. Use `fromHtml()` for HTML.

Chapters start at headings up to `splitLevel`. Text before the first heading becomes a first chapter named after the book. The table of contents lists the chapters; the nav document and the NCX hold the same entries.

## Plain text

`fromText()` splits the text into paragraphs at blank lines (the lines of a paragraph are joined with spaces, as in hard-wrapped e-texts). Each trimmed line is matched against `chapterPattern`; a matching line starts a chapter and becomes its title (`<h1>`). Text before the first chapter line becomes a first chapter named after the book. An invalid pattern, or one that fails while matching (for example by catastrophic backtracking), is refused with a `BuildException`.

## HTML

`fromHtml()` accepts a fragment or a whole document and uses its body. The HTML is reduced to an allowlist before it is written (see [What is removed](#what-is-removed)), then split into chapters at headings up to `splitLevel`, also inside wrapper elements such as `div` and `section`. Ids are made unique across the book (a repeated id or one that is not a valid XML name is dropped), links such as `href="#note"` are rewritten to the chapter that holds the target (`chapter-003.xhtml#note`), and a link whose target does not exist loses its `href`.

## Images

Images are never fetched. An image in the content (`![alt](figures/a.png)`, `<img src="figures/a.png">`) is looked up in `BookOptions::$images` by its path (`.`, `..`, query and fragment are resolved; `%20` is decoded), else by its file name when exactly one supplied image has that name. A match is stored in the book as `images/img-NNN.ext`. Anything else (not supplied, a remote URL, a `data:` URI) is left out and the alt text stays, with a warning in `BuiltBook::$warnings`.

Supplied images must be JPEG, PNG, GIF or WebP, which is checked by content, whatever the name or declared type says. **SVG is not accepted**: an SVG can carry script and remote references. An image that is not usable, or too large, is skipped with a warning, and the cover image is refused with a `BuildException` instead.

## What is removed

The output holds only text, list, table, heading and image elements:

- `script`, `style`, `iframe`, `object`, `embed`, `audio`, `video`, `svg`, `math`, forms and their controls, `link`, `meta` and `base` are removed with their content (an inline SVG whose only content is an `<image>` is replaced by an `<img>` that goes through the image lookup); other unknown elements are replaced by their children.
- Attributes are limited to `id`, `class`, `lang`, `dir`, `title` and the few each element needs (`href`, `src`, `alt`, `width`, `height`, `colspan`, `rowspan`, `start`, ...). There are no `style` attributes and no event handlers.
- Links keep only `http`, `https`, `mailto` and `#fragment` targets; `javascript:`, `data:`, `file:` and relative links lose their `href`.
- Input that is not valid UTF-8 or holds characters XML cannot (control characters) is repaired: invalid bytes become U+FFFD and the control characters are dropped. Metadata with such characters is refused.

## Comics and manga

`fromImages()` builds a fixed-layout (pre-paginated) book with one image per page:

```php
use PhpEpub\Build\BookBuilder;
use PhpEpub\Build\BookOptions;

$pages = [
    ['name' => 'page-1.jpg', 'bytes' => file_get_contents('/path/to/page-1.jpg')],
    ['name' => 'page-2.jpg', 'bytes' => file_get_contents('/path/to/page-2.jpg')],
];

$book = (new BookBuilder(new BookOptions(title: 'My Manga', direction: 'rtl', language: 'ja')))
    ->fromImages($pages, naturalSort: true);
$book->save('/path/to/manga.epub');
```

- Each page's viewport is the size of its image (read from the image's header), the book is marked `rendition:layout` pre-paginated, and `direction` sets `page-progression-direction`.
- The first image is the cover; the navigation document, the NCX and the landmarks list the pages ("Page 1", "Page 2", ...).
- `name` is only used for the natural sort (`page2` before `page10`; pass `naturalSort: true`) and in warnings. Entries that are not usable images (a `Thumbs.db`, an SVG, an image over a limit) are skipped with a warning; when no entry is usable the call throws.

## Limits

`BuildLimits` bounds untrusted input; all values are bytes, pixels or counts:

| Limit | Default | Over it |
|---|---|---|
| `maxChapters` | 1000 | `BuildException` (chapters, or comic pages) |
| `maxTotalBytes` | 100 MiB | `BuildException` (text, CSS, cover and all supplied images together) |
| `maxImages` | 2000 | `BuildException` (images supplied) |
| `maxImageBytes` | 20 MiB | the image is skipped with a warning |
| `maxPixels` | 100 million | the image is skipped with a warning, so a small file cannot expand into a huge bitmap |

A web tool should set `maxTotalBytes` to what it accepts as an upload. The Markdown parser bounds emphasis and code spans (1000 and 2000 characters) so that adversarial text takes linear time, and a regular expression that fails on pathological text leaves the text as it is instead of dropping it. Values of the wrong type in `authors` or `images` are refused with a `BuildException`.

## The book

The book has `mimetype`, `META-INF/container.xml`, `EPUB/package.opf`, `EPUB/nav.xhtml` (EPUB 3 navigation, with landmarks), `EPUB/toc.ncx` (and `<spine toc="ncx">`), `EPUB/css/style.css`, the chapters as `EPUB/text/chapter-NNN.xhtml` (pages as `page-NNN.xhtml`) and the images under `EPUB/images/`. It is valid by the library's own `validate()` (the tests check that no issue is reported) and is also checked with EPUBCheck in CI, like books saved by `EpubFile`. Accessibility metadata (`schema:accessMode`, `accessibilityFeature`, `accessibilityHazard`, `accessibilitySummary`) is included.
