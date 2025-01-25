# ContentManager

The `ContentManager` class in the PHP EPUB Processor library is responsible for managing the content files within an EPUB.
It provides methods to list, add, update, delete, and retrieve content files, ensuring efficient content management within the EPUB structure.

## Key Methods

- **`__construct(string $contentDirectory)`**: Initializes the `ContentManager` with the directory containing the EPUB content. Throws an exception if the directory does not exist.

- **`getContentList(): array`**: Returns a list of all content file paths within the EPUB.

- **`addContent(string $filePath, string $content): void`**: Adds a new content file at the specified path with the given content. Throws an exception if the file cannot be created.

- **`updateContent(string $filePath, string $newContent): void`**: Updates an existing content file with new content. Throws an exception if the file does not exist or cannot be updated.

- **`deleteContent(string $filePath): void`**: Deletes a content file from the EPUB. Throws an exception if the file does not exist or cannot be deleted.

- **`getContent(string $filePath): string`**: Retrieves the content of a specified file. Throws an exception if the file does not exist or cannot be read.

## Usage Example

```php
use PhpEpub\ContentManager;

$contentDirectory = '/path/to/extracted/epub/content';
$contentManager = new ContentManager($contentDirectory);

// List all content files
$contentFiles = $contentManager->getContentList();
print_r($contentFiles);

// Add a new content file
$contentManager->addContent('new-chapter.xhtml', '&lt;h1&gt;New Chapter&lt;/h1&gt;<p>This is a new chapter.</p>');

// Update an existing content file
$contentManager->updateContent('existing-chapter.xhtml', '&lt;h1&gt;Updated Chapter&lt;/h1&gt;<p>Updated content.</p>');

// Delete a content file
$contentManager->deleteContent('old-chapter.xhtml');

// Retrieve content from a file
$content = $contentManager->getContent('chapter1.xhtml');
echo $content;
```
