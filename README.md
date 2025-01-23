# PHP EPUB Processor

A PHP library for processing EPUB files, including parsing, metadata manipulation, and format conversion. This library provides tools to handle EPUB files efficiently, offering features like validation, content management, and conversion to other formats using various adapters.

## Features

- **EPUB Loading and Saving**: Easily load and save EPUB files.
- **Metadata Management**: Read and update metadata such as title, authors, and language.
- **Content Management**: Add, update, and delete content files within an EPUB.
- **Validation**: Validate the structure and content of EPUB files.
- **Conversion**: Convert EPUB files to PDF and other formats using adapters for TCPDF, Dompdf, and Calibre.
- **Logging**: Integrated logging using PSR-3 compliant loggers.

## Installation

To install the library, use Composer:

```bash
composer require indy2kro/php-epub
```

Ensure that you have the necessary PHP extensions and optional libraries installed for full functionality:

- **Required**: `ext-xml`
- **Optional**: `dompdf/dompdf`, `tecnickcom/tcpdf` for PDF conversion, `Calibre` for mobi conversion

## Usage

Loading an EPUB File:

```php
use PhpEpub\EpubFile;

$epubFile = new EpubFile('/path/to/your.epub');
$epubFile->load();
```

Validating an EPUB File

```php
$isValid = $epubFile->validate();
if ($isValid) {
    echo "EPUB is valid.";
} else {
    echo "EPUB is invalid.";
}
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

$metadata = new Metadata('/path/to/opf/file.opf');
$title = $metadata->getTitle();
$metadata->setTitle('New Title');
$metadata->save('/path/to/opf/file.opf');
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

- **PHP Insights**: Provides a comprehensive analysis of code quality, including complexity, architecture, and style. Run it with:

```bash
vendor/bin/phpinsights
```

## Testing

To run the tests, use PHPUnit:

```bash
vendor/bin/phpunit
```