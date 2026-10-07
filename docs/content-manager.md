# ContentManager

The `ContentManager` class provides file-level operations for content within an EPUB. It works with the extracted EPUB directory structure, allowing you to add, update, delete, and retrieve content files.

## Overview

ContentManager operates on the extracted EPUB directory (accessible via `EpubFile::getTempDir()`). All paths are **relative to the book root** and use `/`, for example `EPUB/text/chapter1.xhtml`. Paths that are absolute or escape the book with `..` are rejected.

When it is created by `EpubFile` (or given a `Manifest` and `Spine`), it keeps the OPF in sync:

- `addContent()` adds new files to the manifest, with a media type guessed from the extension.
- In EPUB 3 books, `addContent()` and `updateContent()` set the manifest properties an XHTML document needs because of its content, and remove those it no longer needs: `svg` (inline SVG), `mathml`, `scripted` (`<script>` or `<form>`) and `remote-resources` (a resource loaded from `http(s)://`; links do not count). Other properties, such as `nav`, are kept, and the others are untouched.
- `deleteContent()` removes the file's manifest item and its spine entry, plus package references to it (EPUB 2 cover meta, refinements, `spine@toc`, `fallback`/`media-overlay` of other items, `<guide>` references) and its table-of-contents entries (an entry with children stays as an unlinked heading in the navigation document; the NCX promotes the children).

Adding a file does not put it in the reading order; call `Spine::add()` for that.

The package document (OPF) itself cannot be added, updated or deleted through `ContentManager`: `Metadata`, `Manifest` and `Spine` hold it in memory and `EpubFile::save()` writes it, so a direct write would be overwritten or leave them out of date. These methods throw an `Exception` for the OPF path.

## Key Methods

### Constructor

```php
public function __construct(string $contentDirectory, ?Manifest $manifest = null, ?Spine $spine = null)
```

Initializes the ContentManager with the path to the extracted EPUB. Throws an exception if the directory does not exist. Without a manifest, only files are touched.

Typically, you'll get this from EpubFile:

```php
$epubFile = EpubFile::open('/path/to/book.epub');
$contentManager = $epubFile->getContentManager();
```

### File Operations

```php
public function getContentPaths(): array
```

Returns every file in the book as a sorted list of paths relative to the book root. These paths can be passed straight back to the other methods.

```php
public function getContentList(): array
```

**Deprecated**: returns absolute paths inside the temporary directory. Use `getContentPaths()`.

```php
public function addContent(string $filePath, string $content): void
```

Creates (or overwrites) a file, creating missing directories. New files are added to the manifest, except container files (`mimetype`, `META-INF/…`). An XHTML document (`.xhtml`, `.html` or `.htm`, or a manifest item with media type `application/xhtml+xml`) must be well-formed XML without entity declarations, or an exception is thrown and nothing is written. Throws an exception if the file cannot be written.

```php
public function updateContent(string $filePath, string $newContent): void
```

Updates an existing file's content. XHTML must stay well-formed, as for `addContent()`. Throws an exception if the file doesn't exist or the XHTML is not well-formed.

```php
public function deleteContent(string $filePath): void
```

Deletes a file, its manifest item and its spine entry. Deleting the last file the table of contents links to leaves the table of contents empty instead of failing; `EpubFile::validate()` reports it (`NAV_EMPTY` / `NCX_EMPTY`). Throws an exception if the file doesn't exist or cannot be deleted.

```php
public function moveContent(string $from, string $to, bool $updateReferences = true): void
```

Moves or renames a file. Its manifest item keeps its id, so its place in the reading order is unchanged, and points at the new path; `<guide>` references, table-of-contents entries and the `META-INF/encryption.xml` entry of an obfuscated font follow it. Moving the navigation document rewrites its links for the new location. A case-only rename (`ch.xhtml` to `Ch.xhtml`) also works on case-insensitive filesystems.

References inside content documents follow the move (pass `$updateReferences = false` to leave them alone; `EpubFile::validate()` then reports those that break, `CONTENT_REFERENCE_MISSING`):

- In XHTML and SVG documents, the attributes `src`, `poster`, `data`, and `href` (including `xlink:href`) on `a`, `area`, `link`, `image` and `use` that point at the moved file are rewritten.
- In stylesheets, `<style>` elements and `style` attributes, `url(...)` values and `@import "..."` strings are rewritten.
- A document that moves to another directory gets its own relative references recomputed, so they still reach the same files.

Query strings and fragments are kept. Only documents that change are written, and they are re-serialized with DOM, so details such as quote style, the XML declaration or whitespace inside tags can differ. Documents that are not well-formed XML are left unchanged. Throws an exception if either path is the package document or leaves the book, the file does not exist, the target already exists, or the file cannot be moved or a document cannot be rewritten.

```php
$epubFile->getContentManager()->moveContent('EPUB/chapter1.xhtml', 'EPUB/text/chapter-01.xhtml');
```
```php
public function getContent(string $filePath): string
```

Returns the content of a file. Throws an exception if the file doesn't exist or cannot be read.

## Usage Example

```php
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/book.epub');

$content = $epubFile->getContentManager();

// List existing files
print_r($content->getContentPaths());

// Read a file
$chapter1 = $content->getContent('EPUB/text/chapter1.xhtml');

// Add a new chapter and put it at the end of the reading order
$newChapter = <<<HTML
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head><title>New Chapter</title></head>
<body><h1>New Chapter</h1><p>This is a new chapter.</p></body>
</html>
HTML;
$content->addContent('EPUB/text/chapter3.xhtml', $newChapter);
$item = $epubFile->getManifest()->findByPath('EPUB/text/chapter3.xhtml');
$epubFile->getSpine()->add($item->id);

// Update existing content
$content->updateContent('EPUB/text/chapter1.xhtml', str_replace('Old', 'New', $chapter1));

// Delete content (also removes it from the manifest and spine)
// $content->deleteContent('EPUB/text/unwanted.xhtml');

// Save the EPUB; the OPF changes are written automatically
$epubFile->save();
```

## Error Handling

Throws `Exception` in the following cases:
- Directory doesn't exist (constructor)
- A path is absolute or escapes the book (`InvalidEpubException`)
- File operations fail (permissions, disk space)
- Attempting to update/delete non-existent files

## Integration with EpubFile

ContentManager is created when you call `load()`, together with the `Manifest` and `Spine` it keeps in sync. It shares the lifetime of the temporary directory, which is cleaned up by `EpubFile::cleanup()`, by `__destruct()`, or when `load()` is called again.
