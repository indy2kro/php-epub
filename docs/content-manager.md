# ContentManager

The `ContentManager` class provides file-level operations for content within an EPUB. It works with the extracted EPUB directory structure, allowing you to add, update, delete, and retrieve content files.

## Overview

ContentManager operates on the extracted EPUB directory (accessible via `EpubFile::getTempDir()`). It does not modify the OPF manifest - you must manually update the manifest if you add or remove content files that should be part of the EPUB spine.

## Key Methods

### Constructor

```php
public function __construct(string $contentDirectory)
```

Initializes the ContentManager with the path to the EPUB's content directory. Throws an exception if the directory does not exist.

Typically, you'll get this from EpubFile:

```php
$epubFile->load();
$contentManager = $epubFile->getContentManager();
```

### File Operations

```php
public function getContentList(): array
```

Returns an array of all file paths in the content directory (non-recursive).

```php
public function addContent(string $filePath, string $content): void
```

Creates a new file with the given content. The path is relative to the content directory. Throws an exception if the file cannot be created or already exists.

```php
public function updateContent(string $filePath, string $newContent): void
```

Updates an existing file's content. Throws an exception if the file doesn't exist.

```php
public function deleteContent(string $filePath): void
```

Deletes a file from the EPUB. Throws an exception if the file doesn't exist or cannot be deleted.

```php
public function getContent(string $filePath): string
```

Returns the content of a file. Throws an exception if the file doesn't exist or cannot be read.

## Usage Example

```php
use PhpEpub\EpubFile;

$epubFile = new EpubFile('/path/to/book.epub');
$epubFile->load();

// Get the content manager
$content = $epubFile->getContentManager();

// List existing files
$files = $content->getContentList();
print_r($files);

// Read a file
$chapter1 = $content->getContent('chapter1.xhtml');
echo $chapter1;

// Add a new chapter
$newChapter = <<<HTML
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <title>New Chapter</title>
</head>
<body>
    <h1>New Chapter</h1>
    <p>This is a new chapter.</p>
</body>
</html>
HTML;
$content->addContent('chapter3.xhtml', $newChapter);

// Update existing content
$content->updateContent('chapter1.xhtml', str_replace('Old', 'New', $chapter1));

// Delete content
// $content->deleteContent('unwanted.xhtml');

// Save the EPUB
$epubFile->save();
```

## Important Notes

1. **Manifest Not Updated**: ContentManager only handles the actual files. If you add new content files, you need to manually update the OPF manifest to include them.

2. **Path Handling**: Paths are relative to the content directory. Use forward slashes (/) even on Windows.

3. **Content Types**: ContentManager works with any file type, but for valid EPUB content, use XHTML files (`.xhtml`, `.html`), CSS (`.css`), images, or fonts.

4. **Directory Structure**: The content directory typically contains:
   ```
   EPUB/
   ├── chapter1.xhtml
   ├── chapter2.xhtml
   ├── style.css
   └── images/
       ├── cover.jpg
       └── diagram.png
   ```

## Error Handling

Throws `Exception` in the following cases:
- Directory doesn't exist (constructor)
- File operations fail (permissions, disk space)
- Attempting to update/delete non-existent files

## Integration with EpubFile

ContentManager is automatically created when you call `load()`:

```php
$epubFile = new EpubFile('book.epub');
$epubFile->load(); // This creates the ContentManager internally

// Now you can access it
$content = $epubFile->getContentManager();
```

The ContentManager shares the same lifetime as the temporary directory - it's cleaned up when `EpubFile::cleanup()` or `__destruct()` is called.
