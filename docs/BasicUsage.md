# Basic Usage

## Loading an EPUB File

To load an EPUB file, use the EpubFile class:

```php
use PhpEpub\EpubFile;

$epubFile = new EpubFile('/path/to/your.epub');
$epubFile->load();
```

## Accessing Metadata

Once the EPUB is loaded, you can access and modify its metadata:

```php
$metadata = $epubFile->getMetadata();
$title = $metadata->getTitle();
$metadata->setTitle('New Title');
$metadata->save();
```
