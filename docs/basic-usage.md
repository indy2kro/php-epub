# Basic Usage

## Opening a Book

`EpubFile::open()` extracts a book to a private temporary directory and parses it:

```php
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/your.epub');
```

It is the same as `new EpubFile($path)` followed by `load()`. The temporary directory is deleted when the object is destroyed, or by `cleanup()`.

## Reading and Editing Metadata

```php
$metadata = $epubFile->getMetadata();

echo $metadata->getTitle(), ' by ', implode(', ', $metadata->getAuthors()), "\n";
echo 'ISBN: ', $metadata->getIsbn() ?? 'none', "\n";

$metadata->setTitle('New Title');
$metadata->setSeries('The Expanse', 2);
```

See [Metadata](metadata.md) for every field.

## Saving

```php
$epubFile->save();                    // overwrite the original file
$epubFile->save('/path/to/copy.epub'); // or write a new one
```

`save()` writes pending metadata, manifest and spine changes and packages the book; calling `Metadata::save()` first is not needed.

## Creating a Book

```php
$epubFile = EpubFile::create('/path/to/new.epub', 'My Book', 'en');
$epubFile->getMetadata()->setAuthors(['Ann Author']);
$epubFile->addChapter('Chapter One', '<h1>Chapter One</h1><p>It begins.</p>');
$epubFile->setCoverImage(file_get_contents('/path/to/cover.jpg'), 'image/jpeg');
$epubFile->save();
```

`addChapter()` writes the XHTML document and adds it to the manifest, the reading order and the table of contents.

## Table of Contents

```php
foreach ($epubFile->getTableOfContents()->getEntries() as $entry) {
    echo $entry->title, ' -> ', $entry->path, "\n";
}
```

See [Table of Contents](table-of-contents.md) to change it.

## Checking a Book

```php
foreach ($epubFile->validate() as $issue) {
    echo $issue, "\n"; // e.g. "error MANIFEST_FILE_MISSING (EPUB/images/gone.png): ..."
}
```

`validate()` is a quick check of common problems before publishing; [EPUBCheck](https://www.w3.org/publishing/epubcheck/) remains the reference.

## Converting

```php
use PhpEpub\Converters\DompdfAdapter;

$epubFile->convert(new DompdfAdapter(), '/path/to/output.pdf');
```

Unsaved changes are included. See [Converter](converter.md) for the TCPDF and Calibre adapters.
