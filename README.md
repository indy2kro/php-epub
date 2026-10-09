[![codecov](https://codecov.io/gh/indy2kro/php-epub/graph/badge.svg?token=tg1ntQtebI)](https://codecov.io/gh/indy2kro/php-epub) [![Tests](https://github.com/indy2kro/php-epub/actions/workflows/tests.yml/badge.svg)](https://github.com/indy2kro/php-epub/actions/workflows/tests.yml)

# PHP EPUB Processor

A PHP library for reading and editing EPUB 2 and EPUB 3 books: metadata, cover, manifest, reading order and content files, saved back as valid EPUB archives, plus conversion to PDF and other formats. Books are treated as untrusted input throughout.

- [Documentation](https://indy2kro.github.io/php-epub/)

## Features

- **Open and save**: `EpubFile::open()` extracts a book safely; `save()` writes an OCF-valid archive (checked with EPUBCheck in CI); books can also be opened from and saved to strings and streams.
- **Metadata**: title(s), authors, creators and contributors with roles, description, publisher, dates and language (checked when set), accessibility metadata, subjects, identifiers, and any other `<meta>` element.
- **Cover**: read and replace the cover image (EPUB 3 `cover-image` and EPUB 2 conventions).
- **Package editing**: manifest items, reading order (spine) and table of contents (EPUB 3 nav and EPUB 2 NCX); adding or deleting content keeps them in sync, moving a file rewrites the references to it, and the table of contents can be generated from the chapters' headings.
- **EPUB 3 features**: `upgradeToEpub3()` converts an EPUB 2 book (navigation document, refinements, cover and manifest properties); landmarks (nav and guide) and the page list; fixed-layout rendition metadata and spine properties; media overlays and `media:*` metadata; plain-text extraction per document and per book.
- **New books**: `EpubFile::create()` and `addChapter()` build a valid EPUB 3 from scratch, and `BookBuilder` makes one from Markdown, plain text, HTML or a list of images (fixed-layout comics, left-to-right or right-to-left), with sanitised content, limits for untrusted input and an NCX for EPUB 2 readers; see [Book builder](https://indy2kro.github.io/php-epub/book-builder/).
- **Checks**: loading rejects a book without a readable `container.xml` or package document but, like reading systems, accepts a wrong `mimetype` or a broken NCX; `validate()` reports common problems (a wrong `mimetype`, missing metadata, invalid language tags and dates, manifest and spine inconsistencies, media types that contradict the content, missing manifest properties, missing or broken navigation, missing accessibility metadata). This is not a full validator like EPUBCheck.
- **Cleanup**: `compress()` shrinks a book with presets (unreferenced files, scripts, remote references, unused fonts, image recompression with GD, maximum deflate) and a dry-run report; the reusable `ReferenceGraph` finds the files a book really uses. See [Cleanup and Compression](https://indy2kro.github.io/php-epub/cleanup/).
- **Merge and split**: `Merger` combines 2 to 10 books into one EPUB 3 book (own directory and unique ids per book, a combined table of contents, optional deduplication of identical stylesheets, fonts and images, fonts re-obfuscated for the new identifier) and `Splitter` cuts a book into parts by table of contents, item count, ranges or size, each with only the files it needs and its own identifier. See [Merging and Splitting Books](https://indy2kro.github.io/php-epub/merge-split/).
- **Conversion**: PDF with TCPDF or Dompdf, and any format Calibre's `ebook-convert` supports.
- **Repair and Kindle**: `repair()` fixes the problems `validate()` reports that have a safe fix (missing `dcterms:modified`, language, identifier or navigation, manifest and spine inconsistencies, unlisted files, media types, cover declarations, manifest properties) and lists each change ([docs](docs/repair.md)); `validate(ValidationProfile::kindle())` adds checks for Send to Kindle and KDP with fix hints ([docs](docs/kindle.md)).
- **Export**: plain text, one self-contained sanitised HTML file (scoped CSS, inlined images) and Markdown with its images, safe to show for hostile books; see [Exporters](https://indy2kro.github.io/php-epub/exporters/).
- **Fonts and DRM**: embedded fonts can be added obfuscated (IDPF) and read back de-obfuscated, Dompdf uses a book's obfuscated fonts, and DRM-protected books (encrypted resources, Adobe ADEPT, Readium LCP) are detected, reported by `validate()` and never converted; nothing is ever decrypted.
- **Hostile books**: paths are confined to the book, extraction is limited (zip bombs), XML entity declarations are refused, and PDF renderers cannot load anything outside the book. See [Handling Untrusted EPUBs](https://indy2kro.github.io/php-epub/advanced-usage/#handling-untrusted-epubs).

## Installation

To install the library, use Composer:

```bash
composer require indy2kro/php-epub
```

Ensure that you have the necessary PHP extensions and optional libraries installed for full functionality:

- **Required**: `ext-ctype`, `ext-dom`, `ext-libxml`, `ext-simplexml`, `ext-xml`, `ext-zip` (most PHP builds include all of them; on Alpine, for example, `php-ctype`, `php-dom`, `php-simplexml`, `php-xml` and `php-zip` are separate packages)
- **Optional**: `dompdf/dompdf` or `tecnickcom/tcpdf` for PDF conversion, and [Calibre](https://calibre-ebook.com/) for other formats (MOBI, AZW3, DOCX, …)

## Usage

### Open, edit and save

```php
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/book.epub');

$metadata = $epubFile->getMetadata();
echo $metadata->getTitle();
$metadata->setTitle('New Title');
$metadata->setAuthors(['Jane Doe']);
$metadata->addContributor('Ed Editor', 'edt');

// Writes pending package changes, then packages the book (to a new file here).
$epubFile->save('/path/to/edited.epub');
```

### Strings and streams

```php
// Uploads, HTTP downloads: the same ZIP limits apply as for files.
$epubFile = EpubFile::openString($bytes);       // or EpubFile::openStream($resource)
$epubFile->getMetadata()->setTitle('New Title');

$bytes = $epubFile->saveToString();             // or $epubFile->saveToStream($resource)
```

A book opened this way has no file, so `save()` needs a path (or use the two methods above).

### Create a new book

```php
$epubFile = EpubFile::create('/path/to/new.epub', 'My Book', 'en');
$epubFile->getMetadata()->setAuthors(['Jane Doe']);
// Each chapter is added to the manifest, the reading order and the table of contents.
$epubFile->addChapter('Chapter One', '<h1>Chapter One</h1><p>It begins.</p>');
$epubFile->save();
```

### Cover, content and reading order

```php
$cover = $epubFile->getCoverImage();   // ManifestItem or null
$epubFile->setCoverImage(file_get_contents('/path/to/cover.jpg'), 'image/jpeg');

$content = $epubFile->getContentManager();
$content->addContent('EPUB/text/epilogue.xhtml', $xhtml);   // added to the manifest
$item = $epubFile->getManifest()->findByPath('EPUB/text/epilogue.xhtml');
$epubFile->getSpine()->add($item->id);                       // and to the reading order
$epubFile->getTableOfContents()->addEntry(new \PhpEpub\TocEntry('Epilogue', 'EPUB/text/epilogue.xhtml'));
// (addChapter() does all three in one call)

$epubFile->save();
```

### EPUB 3 features

```php
// Convert an EPUB 2 book in place (false when it already is EPUB 3), then save it.
if ($epubFile->upgradeToEpub3()) {
    $epubFile->save('/path/to/epub3.epub');
}

// Landmarks, fixed layout and plain text.
$epubFile->getTableOfContents()->setLandmarks([
    new \PhpEpub\Landmark('bodymatter', 'Start', 'EPUB/text/chapter-1.xhtml'),
]);
$epubFile->getMetadata()->setRenditionLayout('pre-paginated');
$epubFile->getSpine()->setPageSpread('page-1', 'right');
$words = array_sum(array_map(str_word_count(...), $epubFile->getText()));
```

See [EPUB 3 Features](https://indy2kro.github.io/php-epub/epub3-features/) for the details.

### Converting

```php
use PhpEpub\Converters\TCPDFAdapter;
use PhpEpub\Converters\CalibreAdapter;

// Includes edits that have not been saved yet.
$epubFile->convert(new TCPDFAdapter(), '/path/to/book.pdf');
$epubFile->convert(new CalibreAdapter(['calibre_path' => '/usr/bin/ebook-convert']), '/path/to/book.mobi');
```

### Untrusted uploads

`Limits::web()` bounds a book's entries, size, compression ratio and the size of each XML or XHTML document. `EpubReader` reads a book without extracting it to disk, which suits inspecting an upload per request:

```php
use PhpEpub\EpubFile;
use PhpEpub\EpubReader;
use PhpEpub\Limits;

// Metadata, table of contents, cover and text, read from the archive on demand
$reader = EpubReader::open($uploadedPath, Limits::web());
echo $reader->getMetadata()->getTitle();
$reader->close();

// The full, editable book with the same limits
$epubFile = EpubFile::open($uploadedPath, limits: Limits::web());
$epubFile->close();
```

See [EpubReader and Limits](docs/epub-reader.md).

### Shrinking

```php
use PhpEpub\Cleanup\CleanupPreset;

$report = $epubFile->compress(CleanupPreset::Balanced, '/path/to/smaller.epub');
echo $report->archiveBytesBefore . ' -> ' . $report->archiveBytesAfter . " bytes
";
```

See [Cleanup and Compression](https://indy2kro.github.io/php-epub/cleanup/) for the options and the dry-run report.

### Export to text, HTML and Markdown

```php
use PhpEpub\Converters\HtmlAdapter;
use PhpEpub\Converters\MarkdownAdapter;
use PhpEpub\Converters\TextAdapter;

$epubFile->convert(new TextAdapter(), '/path/to/book.txt');
$epubFile->convert(new HtmlAdapter(), '/path/to/book.html');   // one self-contained, sanitised file

$export = (new MarkdownAdapter())->export($epubFile->getTempDir());
$export->writeTo('/path/to/output');                            // book.md and images/
```

See [Exporters](https://indy2kro.github.io/php-epub/exporters/).

### Build a book from Markdown, text, HTML or images

```php
use PhpEpub\Build\BookBuilder;
use PhpEpub\Build\BookOptions;

$book = (new BookBuilder(new BookOptions(title: 'My Book', authors: ['Jane Doe'], splitLevel: 2)))
    ->fromMarkdown("# One\n\nIt begins.\n\n# Two\n\nIt goes on.\n");
$book->save('/path/to/book.epub');

// A right-to-left, fixed-layout comic with one image per page.
$comic = (new BookBuilder(new BookOptions(title: 'My Manga', direction: 'rtl')))
    ->fromImages([['name' => '1.jpg', 'bytes' => $firstPage], ['name' => '2.jpg', 'bytes' => $secondPage]]);
```

See [Book builder](https://indy2kro.github.io/php-epub/book-builder/).

### Merging and splitting

```php
use PhpEpub\Merge\MergeOptions;
use PhpEpub\Merge\Merger;
use PhpEpub\Split\SplitPlan;
use PhpEpub\Split\Splitter;

(new Merger())->merge([$firstBook, $secondBook], new MergeOptions(title: 'Omnibus'), '/path/to/omnibus.epub');

$paths = (new Splitter())->split($epubFile, SplitPlan::byToc(), '/path/to/parts');
```

See [Merging and Splitting Books](https://indy2kro.github.io/php-epub/merge-split/) for the options, the plans and what the output looks like.

### Errors

All exceptions extend `PhpEpub\Exception`: `ZipException` for archive problems and extraction limits, `InvalidEpubException` (and its subclass `XmlException`) for invalid or unsafe books, `ConversionException` for conversion failures, `ReadOnlyException` for a change asked of an `EpubReader` and `BuildException` for invalid options, empty content or exceeded limits when building a book.

```php
use PhpEpub\EpubFile;
use PhpEpub\InvalidEpubException;
use PhpEpub\ZipException;

try {
    $epubFile = EpubFile::open($uploadedPath);
} catch (ZipException | InvalidEpubException $e) {
    // Not a readable EPUB.
}
```

See the [documentation](https://indy2kro.github.io/php-epub/) for every class and option.

## Code Quality

To maintain high standards of code quality, this project uses several tools:

- **PHPUnit**: Run tests with `vendor/bin/phpunit`

- **PHP CodeSniffer (PHPCS)**: Ensures code adheres to coding standards:

```bash
vendor/bin/phpcs src/ tests/ docs/
```

- **PHPStan**: Static analysis tool for finding bugs:

```bash
php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress
```

- **Rector**: Automated code refactoring and upgrades (review with a dry run first):

```bash
vendor/bin/rector --dry-run
```

### Running All Code Quality Checks

Run all code quality tools at once:

```bash
# Using composer script (recommended)
composer quality

# Or run individually
vendor/bin/phpunit && vendor/bin/phpcs src/ tests/ docs/ && php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress && vendor/bin/rector --dry-run
```

## AI Integration

This project is designed for easy integration with AI coding assistants:

- **AGENTS.md**: Contains instructions for AI agents working on this codebase
- **Consistent class structure**: Key classes (`EpubFile`, `Metadata`, `ContentManager`, etc.) follow predictable patterns
- **Dependency injection**: Core classes accept optional dependencies in constructors for easier mocking
- **Type hints**: All methods use PHP 8+ type hints for better AI understanding

## Testing

To run the tests, use PHPUnit:

```bash
vendor/bin/phpunit
```

`tests/EpubCheckTest.php` also validates books saved by the library with [EPUBCheck](https://www.w3.org/publishing/epubcheck/) when `EPUBCHECK_JAR` points at `epubcheck.jar`, as the CI does.
