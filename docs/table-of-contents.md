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

- **`setEntries(array $entries): void`**: Replaces the entries in the navigation document **and** in the NCX, whichever the book has, so EPUB 3 books that keep an NCX for older readers stay consistent. The `toc` heading and other navs (landmarks, page list) are kept; a missing `toc` nav is created. The NCX has no unlinked entries, so an entry without a path is left out there and its children take its place. Throws an `Exception` when the book has neither file, when an entry points outside the book or is not valid XML text, or when a file cannot be written.

- **`addEntry(TocEntry $entry): void`**: Appends a top-level entry.

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

$epubFile->save();
```

In an EPUB 3 navigation document, an entry without a link (a `<span>`) must have children; EPUBCheck reports a childless one.
