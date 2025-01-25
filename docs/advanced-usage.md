# Advanced Usage

## Modifying EPUB Content

To modify the content within an EPUB file, you can use the ContentManager class to access specific files, make changes, and save them.

```php
use PhpEpub\EpubFile;
use PhpEpub\ContentManager;

$epubFilePath = '/path/to/your.epub';

// Load the EPUB file
$epubFile = new EpubFile($epubFilePath);
$epubFile->load();

// Access the content manager
$tempDir = $epubFile->getTempDir();
$contentManager = new ContentManager($tempDir);

// Retrieve and modify content
$contentFile = 'chapter1.xhtml';
$content = $contentManager->getContent($contentFile);
$modifiedContent = str_replace('Old Text', 'New Text', $content);

// Save the modified content
$contentManager->updateContent($contentFile, $modifiedContent);
```

### Saving Changes

#### Save to the Same File

To save changes to the same EPUB file:

```php
$epubFile->save();
```

This will overwrite the original EPUB file with the modified content.

#### Save as a New File

To save the modified EPUB as a new file:

```php
$newEpubFilePath = '/path/to/new.epub';
$epubFile->save($newEpubFilePath);
```

This will create a new EPUB file with the changes, leaving the original file unchanged.

## Converting EPUB

You can convert an EPUB to PDF using one of the available adapters.


### Convert to PDF Using DompdfAdapter

```php
use PhpEpub\Converters\DompdfAdapter;

$dompdfAdapter = new DompdfAdapter();
$dompdfAdapter->convert('/path/to/extracted/epub', '/path/to/output.pdf');
```

### Convert to PDF Using TCPDFAdapter

To convert using TCPDF:

```php
use PhpEpub\Converters\TCPDFAdapter;

$tcpdfAdapter = new TCPDFAdapter();
$tcpdfAdapter->convert('/path/to/extracted/epub', '/path/to/output.pdf');
```

### Convert to MOBI Using CalibreAdapter

To convert using Calibre:

```php
use PhpEpub\Converters\CalibreAdapter;

$options = [
    'calibre_path' => '/usr/bin/ebook-convert',
    'extra_args' => '--output-profile kindle',
];

$calibreAdapter = new CalibreAdapter($options);
$calibreAdapter->convert('/path/to/input.epub', '/path/to/output.mobi');
```