[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/indy2kro/php-epub/badges/quality-score.png?b=main)](https://scrutinizer-ci.com/g/indy2kro/php-epub/?branch=main) [![codecov](https://codecov.io/gh/indy2kro/php-epub/graph/badge.svg?token=tg1ntQtebI)](https://codecov.io/gh/indy2kro/php-epub) [![Tests](https://github.com/indy2kro/php-epub/actions/workflows/tests.yml/badge.svg)](https://github.com/indy2kro/php-epub/actions/workflows/tests.yml)

# PHP EPUB Processor

A PHP library for processing EPUB files, including parsing, metadata manipulation, and format conversion. This library provides tools to handle EPUB files efficiently, offering features like validation, content management, and conversion to other formats using various adapters.

- [Documentation](https://indy2kro.github.io/php-epub/)

## Features

- **EPUB Loading and Saving**: Easily load and save EPUB files.
- **Metadata Management**: Read and update metadata such as title, authors, and language.
- **Content Management**: Add, update, and delete content files within an EPUB.
- **Validation**: Validate the structure and content of EPUB files.
- **Conversion**: Convert EPUB files to PDF and other formats using adapters for TCPDF, Dompdf, and Calibre.

## Installation

To install the library, use Composer:

```bash
composer require indy2kro/php-epub
```

Ensure that you have the necessary PHP extensions and optional libraries installed for full functionality:

- **Required**: `ext-xml`, `ext-zip`
- **Optional**: `dompdf/dompdf`, `tecnickcom/tcpdf` for PDF conversion, `Calibre` for mobi conversion

## Usage

Loading an EPUB File:

```php
use PhpEpub\EpubFile;

$epubFile = new EpubFile('/path/to/your.epub');
$epubFile->load();
```

## Converting to PDF

Using TCPDF:

```php
use PhpEpub\Converters\TCPDFAdapter;

$adapter = new TCPDFAdapter();
$adapter->convert('/path/to/extracted/epub', '/path/to/output.pdf');
```

## Managing Metadata

```php
use PhpEpub\Metadata;
use PhpEpub\EpubFile;

$epubFilePath = '/path/to/your.epub';

$epubFile = new EpubFile($epubFilePath);
$epubFile->load();

$metadata = $epubFile->getMetadata();
$title = $metadata->getTitle();
$metadata->setTitle('New Title');
$metadata->save();

$epubFile->save();
```

## Code Quality

To maintain high standards of code quality, this project uses several tools:

- **PHP CodeSniffer (PHPCS**): Ensures your code adheres to a set of coding standards. Check the code with:

```bash
vendor/bin/phpcs
```

- **PHPStan**: A static analysis tool for finding bugs in your code. Run it using:

```bash
vendor/bin/phpstan
```

- **Rector**: A tool for automated code refactoring. Use it to apply coding standards and upgrade code:

```bash
vendor/bin/rector
```

## Testing

To run the tests, use PHPUnit:

```bash
vendor/bin/phpunit
```
