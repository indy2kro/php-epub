[![codecov](https://codecov.io/gh/indy2kro/php-epub/graph/badge.svg?token=tg1ntQtebI)](https://codecov.io/gh/indy2kro/php-epub) [![Tests](https://github.com/indy2kro/php-epub/actions/workflows/tests.yml/badge.svg)](https://github.com/indy2kro/php-epub/actions/workflows/tests.yml)

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

- **PHPUnit**: Run tests with `vendor/bin/phpunit`

- **PHP CodeSniffer (PHPCS)**: Ensures code adheres to coding standards:

```bash
vendor/bin/phpcs src/ tests/
```

- **PHPStan**: Static analysis tool for finding bugs:

```bash
php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress
```

- **Rector**: Automated code refactoring and upgrades:

```bash
vendor/bin/rector
```

### Running All Code Quality Checks

Run all code quality tools at once:

```bash
# Using composer script (recommended)
composer quality

# Or run individually
vendor/bin/phpunit && vendor/bin/phpcs src/ tests/ && php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress
```

## AI Integration

This project is designed for easy integration with AI coding assistants:

- **AGENTS.md**: Contains instructions for AI agents working on this codebase
- **Consistent class structure**: Key classes (`EpubFile`, `Metadata`, `ContentManager`, etc.) follow predictable patterns
- **Dependency injection**: Core classes accept optional dependencies in constructors for easier mocking
- **Type hints**: All methods use PHP 8+ type hints for better AI understanding

## Testing

To run the tests, use PHPUnit:

```bash
vendor/bin/phpunit
```
