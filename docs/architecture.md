# Architecture

This page gives an overview of how php-epub is put together.

## Overview

`EpubFile` is the entry point. It extracts the book into a private temporary directory, parses the package document (OPF) once, and hands the same in-memory document to the objects that read and edit it. `save()` writes the OPF back and packs the directory into a new archive.

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

## Core Components

### EpubFile
The facade. `open()`/`load()` extract and parse the book, `create()` starts a new one (with `addChapter()` to fill it), `save()` writes pending package edits and creates the archive, `convert()` runs a converter on the book including unsaved edits. It also handles the cover image (`getCoverImage()`, `setCoverImage()`). `cleanup()` (called by the destructor) deletes the extraction and unloads the book.

### Metadata
Reads and edits the `<metadata>` element. Each Dublin Core field has a trait in `src/Traits/` (title, authors and creators, contributors, description, publisher, date, language, subject, identifier); `getMeta()`/`setMeta()` and `getProperty()`/`setProperty()` cover other `<meta>` elements. Values are checked with `Util\XmlText` before they are written.

### Manifest
Reads and edits the `<manifest>`: items (`ManifestItem`), lookup by id, path or href, adding and removing items (removal also clears references to the item elsewhere in the package), media types and EPUB 3 properties. It converts between hrefs (relative to the OPF) and paths (relative to the book root).

### Spine
The reading order: `SpineItem`s with their manifest item and `linear` flag, and adding, removing and moving entries.

### ContentManager
File operations on the extracted book (add, update, delete, move, read), keeping the manifest and spine in sync and refusing XHTML that is not well-formed; moving a file rewrites the references to it. Paths are relative to the book root; the package document itself is managed only through `Metadata`, `Manifest` and `Spine`.

### TableOfContents
Reads and edits the EPUB 3 navigation document's `toc` nav and the EPUB 2 NCX as `TocEntry` trees, writing both when a book has both.

### Validator
`EpubFile::validate()` runs it to report common structural problems as `ValidationIssue`s: required metadata, duplicate ids, manifest and spine consistency, media types and manifest properties, navigation, accessibility metadata.

### Parser and XmlParser
`Parser` reads `META-INF/container.xml` and locates and checks the OPF; problems reading systems tolerate (a wrong `mimetype`, a broken NCX) are left to `Validator`. `XmlParser` loads XML without network access and rejects entity declarations.

### ZipHandler
Extracts archives with limits (entry count, total size, compression ratio) and writes OCF-valid archives (`mimetype` first and uncompressed, `/` separators).

### Converters
`ConverterInterface` has three adapters: `TCPDFAdapter` and `DompdfAdapter` (PDF, via `EpubDocumentLoader`, which reads the spine documents and makes their HTML safe to render), and `CalibreAdapter` (any format Calibre's `ebook-convert` supports). `Converter` maps formats to adapters.

## Untrusted input

Every byte of a book is treated as hostile: `PathResolver` keeps every path from the book (and from callers) inside the extraction directory, `ZipHandler` limits extraction, `XmlParser` refuses entity declarations, and `EpubDocumentLoader` confines everything the PDF renderers could load to the book. See [Handling Untrusted EPUBs](advanced-usage.md#handling-untrusted-epubs).

## Exceptions

All exceptions extend `PhpEpub\Exception`: `ZipException` (archive problems and extraction limits), `InvalidEpubException` (invalid structure or paths outside the book) with its subclass `XmlException` (unreadable or unsafe XML), and `ConversionException` (PDF conversion failures).

## Design Patterns

1. **Facade**: `EpubFile` provides a simple interface over the components.
2. **Shared document**: `Metadata`, `Manifest` and `Spine` edit the same parsed OPF, which `EpubFile::save()` writes once.
3. **Trait-based composition**: `Metadata` uses a trait per field.
4. **Adapter**: `ConverterInterface` for multiple conversion backends.
5. **Dependency injection**: core classes accept their collaborators in the constructor, which the tests use.

## Testing

- PHPUnit tests in `tests/`; hostile and edge-case books are generated in the tests with `tests/Support/EpubBuilder` instead of being committed as binary fixtures.
- `tests/EpubCheckTest.php` validates books saved by the library with EPUBCheck in CI.
