# TableOfContents

`TableOfContents` reads and edits a book's table of contents: the `toc` nav of the EPUB 3 navigation document and the EPUB 2 NCX. Get it from a loaded book:

```php
$toc = $epubFile->getTableOfContents();
```

Changes are written to the navigation document and NCX straight away and saved with the book by `EpubFile::save()`.

## Entries

Each entry is a `PhpEpub\TocEntry`:

- `title`: the text shown in the table of contents.
- `path`: the target file relative to the book root (e.g. `EPUB/text/ch1.xhtml`), or `""` for a heading without a link. Links to remote URLs or outside the book are read as `""`.
- `fragment`: the anchor inside the file, without `#`, or `null`.
- `children`: nested entries.

## Key Methods

- **`getEntries(): array`**: The entries of the navigation document's `toc` nav, or, for a book without a navigation document, of the NCX. Returns `[]` when the book has neither.

- **`setEntries(array $entries): void`**: Replaces the entries in the navigation document **and** in the NCX, whichever the book has, so EPUB 3 books that keep an NCX for older readers stay consistent. The `toc` heading and other navs (landmarks, page list) are kept; a missing `toc` nav is created. The NCX has no unlinked entries, so an entry without a path is left out there and its children take its place. Throws an `Exception` when `$entries` is empty (a table of contents needs an entry), when the book has neither file, when an entry points outside the book or is not valid XML text, or when a file cannot be written.

- **`addEntry(TocEntry $entry): void`**: Appends a top-level entry.

- **`generateFromHeadings(int $maxLevel = 3): array`**: Builds the table of contents from the headings `h1` to `h$maxLevel` of the spine documents, in reading order, sets it with `setEntries()` and returns the entries. Headings nest by level (an `h2` after an `h1` is its child, also across documents). A heading without an `id` gets one (`toc-1`, `toc-2`, ..., unique in its document), which rewrites that document. Documents that are not well-formed XML, non-linear spine items, the navigation document and headings without text are skipped. Throws an `Exception` when `$maxLevel` is not 1 to 6, when the object has no spine (use `EpubFile::getTableOfContents()`), when the book has no navigation document or NCX, or when no heading is found (a table of contents needs an entry; nothing is changed then).

- **`getLandmarks(): array`** and **`setLandmarks(array $landmarks): void`**: Read and replace the book's landmarks (`PhpEpub\Landmark`: `type`, `title`, `path`, `fragment`) in the navigation document's `landmarks` nav and the EPUB 2 `<guide>`. See [EPUB 3 Features](epub3-features.md#landmarks-and-the-page-list).

- **`getPageList(): array`**: The print page numbers of the navigation document's `page-list` nav or the NCX `pageList`, as `TocEntry` objects; `[]` when the book has none. Read-only.

- **`isAvailable(): bool`**: Whether the book has a navigation document or an NCX that can hold a table of contents, i.e. whether `setEntries()` can work.

- **`getBook(): ?EpubFile`**: The book this table of contents belongs to, when it came from `EpubFile::getTableOfContents()`. Holding the object keeps the book's extracted files alive, so `EpubFile::open($path)->getTableOfContents()` is safe to use on its own.

Deleting a file with `ContentManager::deleteContent()` removes its entries (if that removes the last one, the table of contents stays empty and `EpubFile::validate()` reports `NAV_EMPTY` / `NCX_EMPTY`), and `EpubFile::save()` keeps the NCX `docTitle` in step with the book title. Whenever the NCX is written, its `dtb:depth` meta is set to the real nesting depth of the entries (created when missing, as long as the NCX has a `<head>`).
## Usage Example

```php
use PhpEpub\EpubFile;
use PhpEpub\TocEntry;

$epubFile = EpubFile::open('/path/to/book.epub');

foreach ($epubFile->getTableOfContents()->getEntries() as $entry) {
    echo $entry->title, ' -> ', $entry->path, "\n";
}

// Add a chapter: file, manifest and reading order, then the table of contents.
$epubFile->getContentManager()->addContent('EPUB/text/epilogue.xhtml', $xhtml);
$item = $epubFile->getManifest()->findByPath('EPUB/text/epilogue.xhtml');
$epubFile->getSpine()->add($item->id);
$epubFile->getTableOfContents()->addEntry(new TocEntry('Epilogue', 'EPUB/text/epilogue.xhtml'));

// Or build the whole table of contents from the chapters' h1 to h3 headings.
$epubFile->getTableOfContents()->generateFromHeadings();

$epubFile->save();
```

In an EPUB 3 navigation document, an entry without a link (a `<span>`) must have children; EPUBCheck reports a childless one.
