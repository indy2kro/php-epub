# Architecture

This page gives an overview of how php-epub is put together.

## Overview

`EpubFile` is the entry point for reading, editing and converting. It extracts the book into a private temporary directory, parses the package document (OPF) once, and hands the same in-memory document to the objects that read and edit it. `save()` writes the OPF back and packs the directory into a new archive.

```
                     ┌──────────────────────────────┐
                     │      EpubFile (facade)       │
                     │  open/load · save · convert  │
                     └──────────────┬───────────────┘
        ┌──────────────┬────────────┼─────────────┬─────────────────┐
        ▼              ▼            ▼             ▼                 ▼
   ┌──────────┐  ┌──────────┐  ┌─────────┐  ┌────────────────┐  ┌────────────┐
   │ Metadata │  │ Manifest │  │  Spine  │  │ ContentManager │  │ Converters │
   └────┬─────┘  └────┬─────┘  └────┬────┘  └───────┬────────┘  └─────┬──────┘
        └─────────────┴──────┬──────┘               │                 │
                     one OPF document               │       EpubDocumentLoader
                             │                      │                 │
                     ┌───────┴────────┐     ┌───────┴────────┐        │
                     │ Parser         │     │ PathResolver   │◄───────┘
                     │ XmlParser      │     │ (paths stay    │
                     └───────┬────────┘     │ inside book)   │
                             │              └────────────────┘
                     ┌───────┴────────┐
                     │ ZipHandler     │
                     └────────────────┘
```

`EpubReader` is the alternative entry point for inspecting a book: it reads the archive on demand and never extracts it (see [EpubReader and Limits](epub-reader.md)).

## Core Components

### EpubFile
The facade. `open()`/`load()` extract and parse the book, `create()` starts a new one (with `addChapter()` to fill it), `save()` writes pending package edits and creates the archive, `convert()` runs a converter on the book including unsaved edits. It also handles the cover image (`getCoverImage()`, `setCoverImage()`). `cleanup()` (called by the destructor) deletes the extraction and unloads the book.

### EpubReader
A read-only facade for untrusted uploads: it opens the archive with `ZipArchive`, checks the entry names and limits up front, and reads entries when `getMetadata()`, `getTableOfContents()`, `getCoverImage()`, `getText()` or `getContent()` ask for them, each streamed with the limits applied to the bytes actually read. It reuses `Parser`, `Metadata`, `Manifest`, `Spine`, `TableOfContents`, `Util\CoverLocator` and `Util\SpineText`, so its results match `EpubFile`'s. The trade-off: `Metadata`, `Manifest`, `Spine` and `ContentManager` all work on an extraction directory, so retrofitting a lazy mode into `EpubFile` would have meant guarding every mutating method and the file-based collaborators (`Validator`, `Encryption`, converters). A separate facade keeps the editing path untouched and the read-only guarantee structural: there is no `save()`, content manager or conversion to misuse, `Metadata::save()` and `TableOfContents` changes throw `ReadOnlyException`, and in-memory edits are never persisted. The price is that `validate()`, `isDrmProtected()` and conversion are only available on `EpubFile`.

### Limits
An immutable value object (`Limits::default()`, `Limits::web()`) that builds the `ZipHandler` and `XmlParser` for `EpubFile` and `EpubReader` and carries the size cap for content documents.

### Metadata
Reads and edits the `<metadata>` element. Each Dublin Core field has a trait in `src/Traits/` (title, authors and creators, contributors, description, publisher, date, language, subject, identifier); `getMeta()`/`setMeta()` and `getProperty()`/`setProperty()` cover other `<meta>` elements. Values are checked with `Util\XmlText` before they are written.

### Manifest
Reads and edits the `<manifest>`: items (`ManifestItem`), lookup by id, path or href, adding and removing items (removal also clears references to the item elsewhere in the package), media types and EPUB 3 properties. It converts between hrefs (relative to the OPF) and paths (relative to the book root).

### Spine
The reading order: `SpineItem`s with their manifest item and `linear` flag, and adding, removing and moving entries.

### ContentManager
File operations on the extracted book (add, update, delete, move, read), keeping the manifest and spine in sync and refusing XHTML that is not well-formed; moving a file rewrites the references to it. Paths are relative to the book root; the package document itself is managed only through `Metadata`, `Manifest` and `Spine`.

### TableOfContents
Reads and edits the EPUB 3 navigation document's `toc` nav and the EPUB 2 NCX as `TocEntry` trees, writing both when a book has both. It also reads and writes the landmarks (`Landmark` objects: the nav `landmarks` and the EPUB 2 `<guide>`) and reads the page list.

### Validator
`EpubFile::validate()` runs it to report common structural problems as `ValidationIssue`s: required metadata, duplicate ids, manifest and spine consistency, media types and manifest properties, navigation, accessibility metadata.

### Repairer and KindleChecker
`RepairRepairer` (behind `EpubFile::repair()`) applies the safe fixes for what `Validator` reports through the same `EpubFile` API and returns `AppliedFix`es; `KindleKindleChecker` (selected with `ValidationProfile::kindle()`) adds Send to Kindle and KDP checks as `ValidationIssue`s. See [Repairing a Book](repair.md) and [Checking for Kindle](kindle.md).

### Parser and XmlParser
`Parser` reads `META-INF/container.xml` and locates and checks the OPF; problems reading systems tolerate (a wrong `mimetype`, a broken NCX) are left to `Validator`. `XmlParser` loads XML without network access and rejects entity declarations.

### ZipHandler
Extracts archives with limits (entry count, total size, compression ratio; `Limits` builds one) and writes OCF-valid archives (`mimetype` first and uncompressed, `/` separators).

### Cleanup
`EpubFile::compress()` runs `Cleanup\Cleanup`, which applies the actions of a `CleanupOptions` (unreferenced and stray files, scripts, remote references, unused fonts, image recompression) to the loaded book and returns a `CleanupReport`. `Cleanup\ReferenceGraph` decides which manifest files are reachable from the spine, navigation, NCX and cover by following references through XHTML, SVG, SMIL and CSS; unreadable content is treated conservatively. See [Cleanup and Compression](cleanup.md).

### Merge and Split
`Merge\Merger` combines several books into one EPUB 3 book: each book's files go into their own directory (so their relative references stay valid), manifest ids are prefixed, byte-identical stylesheets, fonts and images can be shared, obfuscated fonts are re-keyed for the new identifier, and the navigation document and NCX are generated. `Split\Splitter` cuts a book into parts (`Split\SplitPlan`: by table of contents, item count, ranges or size), pruning each part to the files `ReferenceGraph` finds reachable from its reading order items. See [Merging and Splitting Books](merge-split.md).

### Converters
`ConverterInterface` has three adapters: `TCPDFAdapter` and `DompdfAdapter` (PDF, via `EpubDocumentLoader`, which reads the spine documents and makes their HTML safe to render), and `CalibreAdapter` (any format Calibre's `ebook-convert` supports). `Converter` maps formats to adapters. `TextAdapter`, `HtmlAdapter` and `MarkdownAdapter` export text, one sanitised HTML file and Markdown from the same loader output (see [Exporters](exporters.md)).

### BookBuilder
Builds a new book from Markdown, text, HTML or images (see [Book builder](book-builder.md)): `MarkdownParser` turns Markdown into HTML (raw HTML is escaped), `Util\HtmlSanitizer` reduces any HTML to an allowlist and resolves images against the supplied map only, `BookBuilder` splits the result into chapters, and `BookPackage` writes the package, navigation document and NCX as an OCF archive through `ZipHandler`. `BuildLimits` bounds chapters, bytes and images.

## Untrusted input

Every byte of a book is treated as hostile: `PathResolver` keeps every path from the book (and from callers) inside the extraction directory, `ZipHandler` limits extraction, `XmlParser` refuses entity declarations and documents over `Limits::$maxXmlBytes` (libxml's depth limit stays in force), and `EpubDocumentLoader` confines everything the PDF renderers could load to the book. See [Handling Untrusted EPUBs](advanced-usage.md#handling-untrusted-epubs).

## Exceptions

All exceptions extend `PhpEpub\Exception`: `ZipException` (archive problems and extraction limits), `InvalidEpubException` (invalid structure or paths outside the book) with its subclass `XmlException` (unreadable or unsafe XML), `ConversionException` (conversion failures), `ReadOnlyException` (a change asked of an `EpubReader`) and `BuildException` (invalid options, no content or a limit exceeded when building a book).

## Design Patterns

1. **Facade**: `EpubFile` provides a simple interface over the components.
2. **Shared document**: `Metadata`, `Manifest` and `Spine` edit the same parsed OPF, which `EpubFile::save()` writes once.
3. **Trait-based composition**: `Metadata` uses a trait per field.
4. **Adapter**: `ConverterInterface` for multiple conversion backends.
5. **Dependency injection**: core classes accept their collaborators in the constructor, which the tests use.

## Testing

- PHPUnit tests in `tests/`; hostile and edge-case books are generated in the tests with `tests/Support/EpubBuilder` instead of being committed as binary fixtures.
- `tests/EpubCheckTest.php` validates books saved by the library with EPUBCheck in CI.
