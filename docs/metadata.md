# Metadata

The `Metadata` class provides comprehensive management of Dublin Core metadata within an EPUB file. It uses a trait-based approach to organize metadata operations, with each trait handling a specific metadata field.

## Overview

EPUB files use Dublin Core metadata elements (dc:) defined in the OPF (Open Packaging Format) file. The Metadata class provides get/set methods for all commonly used Dublin Core elements, along with persistence to save changes back to the OPF XML file.

## Key Methods

### Constructor

```php
public function __construct(SimpleXMLElement $opfXml, string $opfFilePath)
```

Initializes the Metadata object. The OPF XML element is typically obtained from parsing the EPUB's OPF file. The `$opfFilePath` is needed to save changes back to disk.

### Saving Changes

```php
public function save(): void
```

Serializes the modified OPF XML back to disk. Must be called after making any metadata changes. Throws an exception if the file cannot be written.

```php
public function getOpfFilePath(): string
```

Returns the file path of the OPF file for reference.

## Metadata Fields

The following Dublin Core elements are supported:

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

The language of the resource (dc:language). Use RFC 3066 language codes (e.g., "en", "fr").

### Subject

```php
public function getSubject(): string
public function setSubject(string $subject): void
```

The topic of the resource (dc:subject). Can be used for keywords or topics.

### Identifier

```php
public function getIdentifier(): string
public function setIdentifier(string $identifier): void
```

An unambiguous reference to the resource (dc:identifier). Often an ISBN or UUID.

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
$metadata->setLanguage('en');

// Save metadata changes to OPF file
$metadata->save();

// Save the complete EPUB
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

Each trait provides the get/set methods for a specific field and uses the protected `$opfXml` and `$dcNamespace` properties to interact with the OPF XML structure.

## Error Handling

The `save()` method throws an exception if:
- The OPF file cannot be written
- The XML cannot be serialized

## XML Namespace

The class automatically detects the Dublin Core namespace from the OPF XML on construction. This is necessary because different EPUB files may use different namespace prefixes.
