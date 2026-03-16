# AI Agent Instructions

This document provides instructions for AI agents working on this codebase.

## Code Quality

**Always run code quality tools after making changes. Run them in this order:**

```bash
# 1. Run PHPUnit tests first
vendor/bin/phpunit

# 2. Run PHPCS for code style
vendor/bin/phpcs src/ tests/ docs/

# 3. Run PHPStan for static analysis
php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress

# 4. Run Rector (review changes carefully - see below)
vendor/bin/rector --dry-run
```

**Important notes about Rector:**

- Rector runs on all files (src/ and tests/)
- Some rules are skipped for specific files in rector.php to avoid PHPStan conflicts
- The `NewInInitializerRector` rule is skipped for `src/EpubFile.php` because it generates nullable parameters that PHPStan doesn't like
- Always run Rector with `--dry-run` first and review the changes

**Using composer script (recommended):**

```bash
composer quality
```

Note: The composer script includes Rector with `--dry-run` to check but not apply changes.

## Project Structure

```
src/
├── EpubFile.php          # Main facade class
├── Metadata.php         # Dublin Core metadata management
├── ContentManager.php   # Content file operations
├── Parser.php           # EPUB structure validation
├── Spine.php            # Reading order management
├── ZipHandler.php       # ZIP archive operations
├── XmlParser.php        # XML parsing utilities
├── Converters/          # PDF conversion adapters
│   ├── TCPDFAdapter.php
│   ├── DompdfAdapter.php
│   └── CalibreAdapter.php
└── Traits/              # Metadata field traits
    ├── InteractsWithTitle.php
    ├── InteractsWithAuthors.php
    └── ...
tests/                   # PHPUnit tests
docs/                   # Documentation
```

## Key Classes

### EpubFile
Main entry point. Load EPUB, access metadata/spine/content, save changes.
- `load()` - Extract and parse EPUB
- `save()` - Compress and save
- `getMetadata()` - Access Dublin Core metadata
- `getSpine()` - Access reading order
- `getContentManager()` - Manage content files

### Metadata
Manages EPUB metadata using traits for each Dublin Core field.
- Title, Authors, Publisher, Language, Date, Description, Subject, Identifier
- `save()` - Persist changes to OPF file

### ContentManager
File-level operations on extracted EPUB contents.
- `getContentList()`, `getContent()`, `addContent()`, `updateContent()`, `deleteContent()`

### Parser
Validates EPUB structure and locates OPF file.

### Spine
Represents reading order - which content to display and in what order.

## Testing

- PHPUnit is used for testing
- 3 tests are skipped by default (require Calibre installation)
- Run tests with: `vendor/bin/phpunit`

## PHP Version

- Minimum: PHP 8.3
- Current development: PHP 8.5

## Common Patterns

### Dependency Injection
Core classes accept optional dependencies for testing:
```php
$epub = new EpubFile($path, $mockZipHandler, $mockXmlParser);
```

### Error Handling
Methods throw `Exception` when used incorrectly (e.g., calling `getMetadata()` before `load()`).
