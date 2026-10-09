# Repairing a book

`EpubFile::repair()` fixes the problems [`validate()`](epub-file.md#validating) reports that have one safe, deterministic fix, and returns every change it made. It works on the loaded book, including unsaved edits; `save()` writes the result.

```php
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/book.epub');

foreach ($epubFile->repair() as $fix) {
    echo $fix, "\n"; // e.g. "MANIFEST_FILE_MISSING (EPUB/gone.xhtml): Removed the manifest item "gone", which has no file, from the manifest and the spine."
}

$epubFile->save('/path/to/repaired.epub');
```

```php
public function repair(?RepairOptions $options = null): array
```

Returns a list of `PhpEpub\Repair\AppliedFix` objects with a `code` (the `ValidationIssue` code the change resolves), a `description` and a `location` (a file relative to the book root or a package value, or `null`). The list is empty when the book needed nothing. A second `repair()` changes nothing, and re-running `validate()` reports none of the fixed codes for that book.

A repair never deletes a file of the book. `PhpEpub\Repair\Repairer` is the class behind `EpubFile::repair()`; `new Repairer($epubFile)` does the same.

## What is fixed

| Fix (`RepairFix`) | Codes | What it does |
|---|---|---|
| `ModifiedDate` | `METADATA_MODIFIED_MISSING` | Sets `dcterms:modified` to the current time (UTC) |
| `Language` | `METADATA_LANGUAGE_MISSING` | Sets `dc:language` to the default language (`en`) |
| `Identifier` | `METADATA_IDENTIFIER_MISSING`, `METADATA_UNIQUE_IDENTIFIER` | Gives the package a unique identifier: a `urn:uuid:` when it has no value, else its first non-empty `dc:identifier` is made the unique one |
| `Navigation` | `NAV_MISSING`, `NCX_MISSING`, `NAV_EMPTY`, `NCX_EMPTY` | Creates the navigation document (from the NCX, else the headings, else the reading order) or the NCX (from the headings, else the reading order); fills an empty table of contents from the headings, else the reading order |
| `TocLinks` | `TOC_LINK_NOT_IN_MANIFEST` | Drops table-of-contents entries that link to a file that is not in the manifest; an entry with children stays as a heading |
| `MissingFiles` | `MANIFEST_FILE_MISSING` | Removes a manifest item whose file is missing from the manifest and the spine |
| `SpineReferences` | `SPINE_UNKNOWN_IDREF`, `SPINE_DUPLICATE_IDREF` | Removes spine references to unknown items, and repeated references (the first stays) |
| `UnlistedFiles` | `FILE_NOT_IN_MANIFEST` | Adds the file to the manifest. An XHTML document is **not** added to the spine: the reading order is a decision for the author. Editor and system files (`.DS_Store`, `Thumbs.db`, `desktop.ini`, `__MACOSX/`, any hidden file) are left alone |
| `MediaTypes` | `MEDIA_TYPE_MISMATCH` | Corrects an image's media type from its content, and a type declared as XHTML for a file that is not XML from its extension; a file that cannot be decided stays reported |
| `DuplicateIds` | `DUPLICATE_ID` | Gives a manifest item whose id an earlier item uses a new id (`id-2`). The first keeps the id, so the spine and other references keep pointing at it. Repeated ids outside the manifest are not fixed |
| `Cover` | `COVER_NOT_IMAGE` | Removes the `cover-image` property from an item that is not an image; completes a half-declared cover (the EPUB 3 `cover-image` property and the EPUB 2 `cover` meta, including a cover named by the `<guide>`); declares an image named `cover.*` or `cover-image.*` when the book declares none. Reported as `COVER_NOT_FLAGGED` and `COVER_NOT_DECLARED` |
| `ManifestProperties` | `MANIFEST_PROPERTY_MISSING`, `MANIFEST_PROPERTY_UNNEEDED` | Recomputes the `scripted`, `svg`, `remote-resources` and `mathml` properties of the XHTML documents |
| `UpgradeToEpub3` | (none) | Converts an EPUB 2 book with `upgradeToEpub3()`; reported as `UPGRADED_TO_EPUB3`. **Off by default** |

Everything else `validate()` reports has no safe fix and stays (a missing title, XHTML that is not well-formed, content documents that refer to missing files, hrefs that point outside the book, unreadable navigation).

## Choosing the fixes

```php
use PhpEpub\Repair\RepairFix;
use PhpEpub\Repair\RepairOptions;

$options = (new RepairOptions())
    ->without(RepairFix::UnlistedFiles)
    ->with(RepairFix::UpgradeToEpub3)
    ->withDefaultLanguage('fr');

$fixes = $epubFile->repair($options);

// Only these:
$fixes = $epubFile->repair(RepairOptions::only(RepairFix::Language, RepairFix::ModifiedDate));
```

```php
public function __construct(?array $fixes = null, string $defaultLanguage = 'en', ?DateTimeInterface $now = null)
public static function only(RepairFix ...$fixes): self
public function with(RepairFix ...$fixes): self
public function without(RepairFix ...$fixes): self
public function withDefaultLanguage(string $language): self
public function withNow(?DateTimeInterface $now): self
public function includes(RepairFix $fix): bool
```

`$fixes` defaults to every fix except `UpgradeToEpub3` (`RepairFix::defaults()`). The default language must be a well-formed BCP 47 tag, else an `Exception` is thrown. `$now` is the time written as a missing `dcterms:modified` (the current time when `null`); pass a fixed time in tests.
