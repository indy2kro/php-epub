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

`save()` serializes the OPF XML back to disk. For EPUB 3 packages, `dcterms:modified` is set to the current UTC time when metadata was changed since the last save (it is left alone when nothing changed). Throws an exception if the file cannot be written.

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
```

The name given to the resource (dc:title).

### Authors

```php
public function getAuthors(): array<int, string>
public function setAuthors(array<int, string> $authors): void
```

The creators of the resource (dc:creator). Multiple authors are supported.

`setAuthors()` reuses the existing creators in order, so their roles survive (EPUB 2 `opf:role`, EPUB 3 `<meta refines="#id" property="role">`). When a creator's name changes, its sort key (`opf:file-as` / `file-as` refinement) is removed because it described the old name. Creators that are no longer needed are removed together with all their refinements.

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
```

Date of publication (dc:date). Should be in a valid date format (preferably ISO 8601).

### Language

```php
public function getLanguage(): string
public function setLanguage(string $language): void
```

The language of the resource (dc:language). Use BCP 47 language codes (e.g., "en", "fr").

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
public function setIdentifiers(array<int, string> $identifiers): void
```

Unambiguous references to the resource (dc:identifier), such as an ISBN or UUID.

The first value passed to `setIdentifiers()` is stored in the identifier that `package@unique-identifier` points to, so the package stays valid. At least one identifier is required. An identifier whose value changes loses its type information (`opf:scheme` / `identifier-type` refinement).

### Other `<meta>` Elements

```php
public function getMeta(string $name): ?string
public function setMeta(string $name, ?string $content): void
public function getProperty(string $property): ?string
public function setProperty(string $property, ?string $value): void
public function getVersion(): string
```

Access to metadata beyond the Dublin Core fields:

- `getMeta()`/`setMeta()` read and write EPUB 2 style `<meta name="…" content="…"/>` elements, such as `calibre:series`, `calibre:series_index` or `cover`.
- `getProperty()`/`setProperty()` read and write EPUB 3 `<meta property="…">value</meta>` elements that describe the whole book, such as `belongs-to-collection` or `schema:accessMode`. Refinements of other elements (`refines="#id"`) are ignored.
- Passing `null` as the value removes the element. Only the first matching element is read or updated.
- `getVersion()` returns the package version, e.g. `2.0` or `3.0`.

```php
$metadata->setMeta('calibre:series', 'The Expanse');
$metadata->setMeta('calibre:series_index', '1');
$metadata->setProperty('schema:accessMode', 'textual');
```

## Usage Example

```php
use PhpEpub\EpubFile;

$epubFile = new EpubFile('/path/to/your.epub');
$epubFile->load();

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
- `InteractsWithAuthors` - Author handling
- `InteractsWithPublisher` - Publisher handling
- `InteractsWithLanguage` - Language handling
- `InteractsWithSubject` - Subject handling
- `InteractsWithIdentifier` - Identifier handling

Each trait is a thin layer over shared protected helpers in `Metadata` (`getDcValue()`, `getDcValues()`, `setDcValue()`, `setDcValues()`), which look up elements inside `<metadata>` by namespace URI rather than by prefix.

## Error Handling

- The constructor throws `InvalidEpubException` if the package has no `<metadata>` element.
- `setIdentifiers([])` throws an `Exception`, because a package needs at least one identifier.
- `save()` throws an `Exception` if the OPF file cannot be written.
