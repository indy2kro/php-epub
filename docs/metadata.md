# Metadata

The `Metadata` class provides comprehensive management of Dublin Core metadata within an EPUB file. It uses a trait-based approach to organize metadata operations, with each trait handling a specific metadata field.

## Overview

EPUB files use Dublin Core metadata elements (dc:) defined in the OPF (Open Packaging Format) file. The Metadata class provides get/set methods for all commonly used Dublin Core elements, along with persistence to save changes back to the OPF XML file.

Only elements inside the package's `<metadata>` element are read or changed. Both EPUB 2 and EPUB 3 packages are supported, including packages that use a prefix for the OPF namespace (`<opf:package>`) or that do not declare a `dc` prefix yet.

## Key Methods

### Constructor

```php
public function __construct(SimpleXMLElement $opfXml, string $opfFilePath)
```

Initializes the Metadata object. The OPF XML element is typically obtained from parsing the EPUB's OPF file. The `$opfFilePath` is needed to save changes back to disk. Throws an `InvalidEpubException` if the package has no `<metadata>` element.

### Saving Changes

```php
public function save(): void
public function isModified(): bool
```

`save()` serializes the OPF XML back to disk. When metadata was changed since the last save, the modification date is updated (it is left alone when nothing changed): for EPUB 3 packages `dcterms:modified` is set to the current UTC time, and for EPUB 2 packages an existing `dc:date` with `opf:event="modification"` is set to the current UTC date (none is added). Throws an exception if the file cannot be written.

`isModified()` tells whether there are unsaved changes. `EpubFile::save()` calls `Metadata::save()` automatically when there are, so calling it yourself is optional.

```php
public function getOpfFilePath(): string
```

Returns the file path of the OPF file for reference.

## Metadata Fields

Single-valued getters return the first matching element (or `''`); setters update it, or create it when missing. Values are escaped, so characters such as `&` and `<` are safe.

### Title

```php
public function getTitle(): string
public function setTitle(string $title): void
public function getTitles(): array
public function setTitles(array $titles): void
```

The name given to the resource (dc:title). A book can have several titles; in EPUB 3 a `title-type` refinement says which is the `main` title and which are subtitles, collection titles, and so on.

- `getTitle()`/`setTitle()` work on the main title: the one refined as `main`, or else the first. Other titles are kept.
- `getTitles()`/`setTitles()` read and replace all titles in document order. Existing titles are reused in order, so their `title-type` refinements stay with their position; a title whose text changes loses its `file-as` sort key.
- A book needs a title: `setTitles([])` and empty values throw an `Exception`.

### Authors

```php
public function getAuthors(): array<int, string>
public function setAuthors(array<int, string> $authors): void
public function getCreators(): array<int, Contributor>
public function addCreator(string $name, ?string $role = 'aut', ?string $fileAs = null): void
```

The authors are the creators of the resource (dc:creator) with the role `aut` or no role at all; creators with another role, such as illustrators (`ill`), are not authors. Multiple authors are supported.

`setAuthors()` only replaces the authors: other creators are kept. It reuses the existing authors in order, so their roles survive (EPUB 2 `opf:role`, EPUB 3 `<meta refines="#id" property="role">`). When an author's name changes, its sort key (`opf:file-as` / `file-as` refinement) is removed because it described the old name. Authors that are no longer needed are removed together with all their refinements.

`getCreators()` returns every creator as a `PhpEpub\Contributor` with `name`, `role` (a [MARC relator code](https://www.loc.gov/marc/relators/relaterm.html) such as `aut`, `ill` or `trl`, or `null`) and `fileAs` (the sort key, or `null`). `addCreator()` adds one, writing the role and sort key as EPUB 3 refinements (`scheme="marc:relators"`) or as EPUB 2 `opf:role` / `opf:file-as` attributes, depending on the package version.

### Contributors

```php
public function getContributors(): array<int, Contributor>
public function setContributors(array<int, string> $names): void
public function addContributor(string $name, ?string $role = null, ?string $fileAs = null): void
```

People or organisations who contributed to the resource (dc:contributor), such as editors (`edt`) or translators (`trl`). These work like the creator methods; `setContributors()` reuses existing contributors in order, keeping their roles.

```php
$metadata->addCreator('Ivan Illustrator', 'ill', 'Illustrator, Ivan');
$metadata->addContributor('Ed Editor', 'edt');

foreach ($metadata->getContributors() as $contributor) {
    echo "{$contributor->name} ({$contributor->role})\n";
}
```

### Description

```php
public function getDescription(): string
public function setDescription(string $description): void
```

An account of the resource (dc:description).

### Publisher

```php
public function getPublisher(): string
public function setPublisher(string $publisher): void
```

The entity that made the resource available (dc:publisher).

### Date

```php
public function getDate(): string
public function setDate(string $date): void
public function getModifiedDate(): ?string
public function getDateEvents(): array
```

Date of publication (dc:date). Should be in a valid date format (preferably ISO 8601).

- `getDate()`/`setDate()` use the publication date: the `dc:date` with the EPUB 2 `opf:event="publication"`, or else one without an event, or else the first. Dates of other events are kept.
- `getModifiedDate()` returns the EPUB 3 `dcterms:modified` property, or else the EPUB 2 `dc:date` with `opf:event="modification"`, or `null`. `save()` keeps both up to date.
- `getDateEvents()` returns every `dc:date` keyed by its `opf:event` (`""` for a date without one), e.g. `['publication' => '1999-01-01', 'modification' => '2020-05-05']`.

### Language

```php
public function getLanguage(): string
public function setLanguage(string $language): void
public function getLanguages(): array<int, string>
public function setLanguages(array<int, string> $languages): void
```

The language of the resource (dc:language). Use BCP 47 language codes (e.g., "en", "fr"). `getLanguage()`/`setLanguage()` work on the main (first) language; `getLanguages()`/`setLanguages()` on all of them, for multilingual books. At least one language is required.

### Subject

```php
public function getSubject(): string
public function setSubject(string $subject): void
public function getSubjects(): array<int, string>
public function setSubjects(array<int, string> $subjects): void
```

The topics of the resource (dc:subject). `getSubject()`/`setSubject()` work on the first subject; `getSubjects()`/`setSubjects()` work on all of them.

### Identifiers

```php
public function getIdentifiers(): array<int, string>
public function getUniqueIdentifier(): ?string
public function setIdentifiers(array<int, string> $identifiers): void
public function getTypedIdentifiers(): array<int, Identifier>
public function getIsbn(): ?string
```

Unambiguous references to the resource (dc:identifier), such as an ISBN or UUID.

The first value passed to `setIdentifiers()` is stored in the identifier that `package@unique-identifier` points to, so the package stays valid. At least one identifier is required. An identifier whose value changes loses its type information (`opf:scheme` / `identifier-type` refinement). `getUniqueIdentifier()` returns that identifier, or `null` when `package@unique-identifier` points to none.

Obfuscated fonts (listed in `META-INF/encryption.xml`) are keyed with the unique identifier, so when it changes, `EpubFile::save()` re-keys them: fonts obfuscated with the IDPF algorithm work with any identifier, while Adobe's older algorithm needs a `urn:uuid:` identifier, and saving throws an `Exception` naming the font otherwise.

`getTypedIdentifiers()` returns `PhpEpub\Identifier` objects (`value`, `scheme`). The scheme, in upper case, comes from the EPUB 2 `opf:scheme` attribute, else the EPUB 3 `identifier-type` refinement (ONIX codes `02` and `15` read as `ISBN`, `06` as `DOI`), else a `urn:<scheme>:` or `doi:` prefix, else a valid ISBN check digit; it is `null` when none of these applies. `getIsbn()` returns the first ISBN without prefix, hyphens or spaces (e.g. `9780306406157`), or `null`.

```php
foreach ($metadata->getTypedIdentifiers() as $identifier) {
    echo $identifier->scheme ?? 'unknown', ': ', $identifier->value, "\n";
}
```

### Other Dublin Core Elements

```php
public function getDublinCoreValues(string $element): array<int, string>
public function setDublinCoreValues(string $element, array<int, string> $values): void
```

Read and write any of the fifteen Dublin Core elements by name, including those without dedicated methods: `rights` (licence text), `source`, `type`, `format`, `relation` and `coverage`. `setDublinCoreValues()` replaces every element with that name (`[]` removes them all), reusing existing elements in order so the ids and refinements of unchanged values survive. Title and language still need at least one non-empty value, and identifiers are set with `setIdentifiers()`. Both throw an `Exception` for a name that is not a Dublin Core element.

```php
$metadata->setDublinCoreValues('rights', ['CC BY 4.0']);
print_r($metadata->getDublinCoreValues('source'));
```

### Series

```php
public function getSeries(): ?string
public function getSeriesIndex(): ?string
public function setSeries(?string $name, int|float|string|null $index = null): void
```

Books record their series in two ways: EPUB 3 `<meta property="belongs-to-collection">` refined with `collection-type` `series` (and `group-position` for the position), and Calibre's `calibre:series` and `calibre:series_index` metas, which EPUB 2 books and many reading systems use. `getSeries()` and `getSeriesIndex()` read the EPUB 3 series collection, or else the Calibre metas, and return `null` when there is none. The index is returned as written, e.g. `"2"` or `"2.5"`.

`setSeries()` writes the Calibre metas and, for EPUB 3 packages, a series collection that replaces the previous one; other collections are kept. `null` removes the series (and its index); a call without an index removes the old index. It throws an `Exception` for an empty name or an index that is not a number.

```php
$metadata->setSeries('The Expanse', 2);
echo $metadata->getSeries(), ' #', $metadata->getSeriesIndex(); // The Expanse #2
```

### Other `<meta>` Elements

```php
public function getMeta(string $name): ?string
public function setMeta(string $name, ?string $content): void
public function getProperty(string $property): ?string
public function setProperty(string $property, ?string $value): void
public function getMetaValues(string $name): array
public function setMetaValues(string $name, array $values): void
public function getPropertyValues(string $property): array
public function setPropertyValues(string $property, array $values): void
public function getVersion(): string
```

Access to metadata beyond the Dublin Core fields:

- `getMeta()`/`setMeta()` read and write EPUB 2 style `<meta name="…" content="…"/>` elements, such as `calibre:series`, `calibre:series_index` or `cover`.
- `getProperty()`/`setProperty()` read and write EPUB 3 `<meta property="…">value</meta>` elements that describe the whole book, such as `belongs-to-collection` or `schema:accessMode`. Refinements of other elements (`refines="#id"`) are ignored.
- Passing `null` as the value removes the element. `getMeta()`/`getProperty()` return the first match; `setMeta()`/`setProperty()` replace every match with the single new value, so no stale duplicates are left.
- `getMetaValues()`/`setMetaValues()` and `getPropertyValues()`/`setPropertyValues()` read and write all elements with that name or property as a list (e.g. several `dcterms:subject` properties); an empty list removes them all.
- `getVersion()` returns the package version, e.g. `2.0` or `3.0`.

```php
$metadata->setMeta('calibre:title_sort', 'Expanse, The');
$metadata->setProperty('schema:accessMode', 'textual');
```

## Usage Example

```php
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/your.epub');

$metadata = $epubFile->getMetadata();

// Read metadata
echo "Title: " . $metadata->getTitle();
echo "Authors: " . implode(', ', $metadata->getAuthors());
echo "Language: " . $metadata->getLanguage();

// Update metadata
$metadata->setTitle('My New Title');
$metadata->setAuthors(['John Doe', 'Jane Smith']);
$metadata->setPublisher('My Publishing House');
$metadata->setSubjects(['Fiction', 'Adventure']);
$metadata->setLanguage('en');

// Save the complete EPUB (pending metadata changes are written first)
$epubFile->save();
```

## Internal Implementation

The Metadata class uses PHP traits to organize code:

- `InteractsWithTitle` - Title handling
- `InteractsWithDescription` - Description handling
- `InteractsWithDate` - Date handling
- `InteractsWithAuthors` - Author and creator handling
- `InteractsWithContributors` - Contributor handling
- `InteractsWithPublisher` - Publisher handling
- `InteractsWithLanguage` - Language handling
- `InteractsWithSubject` - Subject handling
- `InteractsWithIdentifier` - Identifier handling
- `InteractsWithSeries` - Series handling (EPUB 3 collections and Calibre metas)

Each trait is a thin layer over shared protected helpers in `Metadata` (`getDcValue()`, `getDcValues()`, `setDcValue()`, `setDcValues()`), which look up elements inside `<metadata>` by namespace URI rather than by prefix.

## Error Handling

- The constructor throws `InvalidEpubException` if the package has no `<metadata>` element.
- `setIdentifiers([])` throws an `Exception`, because a package needs at least one identifier.
- `setTitle()`, `setLanguage()` and `setIdentifiers()` throw an `Exception` for an empty or whitespace-only value, because the OPF specification requires these elements to have content.
- Every setter (including `setMeta()` and `setProperty()`) throws an `Exception` for a value that is not valid UTF-8 or contains a character XML cannot store (control characters other than tab, newline and carriage return). Convert legacy encodings first, e.g. with `mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1')`. The package is left unchanged when a value is rejected, even if it was one of several in a list.
- `save()` throws an `Exception` if the OPF file cannot be written.
