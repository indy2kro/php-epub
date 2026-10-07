# ContentManager

The `ContentManager` class provides file-level operations for content within an EPUB. It works with the extracted EPUB directory structure, allowing you to add, update, delete, and retrieve content files.

## Overview

ContentManager operates on the extracted EPUB directory (accessible via `EpubFile::getTempDir()`). All paths are **relative to the book root** and use `/`, for example `EPUB/text/chapter1.xhtml`. Paths that are absolute or escape the book with `..` are rejected.

When it is created by `EpubFile` (or given a `Manifest` and `Spine`), it keeps the OPF in sync:

- `addContent()` adds new files to the manifest, with a media type guessed from the extension.
- In EPUB 3 books, `addContent()` and `updateContent()` set the manifest properties an XHTML document needs because of its content, and remove those it no longer needs: `svg` (inline SVG), `mathml`, `scripted` (`<script>` or `<form>`) and `remote-resources` (a resource loaded from `http(s)://`; links do not count). Other properties, such as `nav`, are kept, and a document that is not well-formed XML keeps its properties.
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

Creates (or overwrites) a file, creating missing directories. New files are added to the manifest, except container files (`mimetype`, `META-INF/…`). Throws an exception if the file cannot be written.

```php
public function updateContent(string $filePath, string $newContent): void
```

Updates an existing file's content. Throws an exception if the file doesn't exist.

```php
public function deleteContent(string $filePath): void
```

Deletes a file, its manifest item and its spine entry. Throws an exception if the file doesn't exist or cannot be deleted.

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
