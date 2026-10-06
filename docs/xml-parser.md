# XmlParser

The `XmlParser` class in the PHP EPUB Processor library is responsible for handling XML file operations.
It provides methods to load and save XML files, ensuring that the XML data is correctly parsed and stored.

## Key Methods

- **`parse(string $filePath): SimpleXMLElement`**: Loads an XML file from the specified path and returns it as a `SimpleXMLElement`. Documents with entity declarations (`<!ENTITY`) are rejected, whatever their encoding, and the parser never fetches external resources, so DOCTYPE references in EPUB 2 NCX files are left alone. Throws an `XmlException` (a subclass of `InvalidEpubException`) if the file cannot be found or loaded; the message includes the first libxml error and its line.

- **`save(SimpleXMLElement $xml, string $filePath): void`**: Saves a `SimpleXMLElement` to the specified file path. Throws an exception if the file cannot be saved.

## Usage Example

```php
use PhpEpub\XmlParser;

$xmlParser = new XmlParser();

try {
    // Load an XML file
    $xml = $xmlParser->parse('/path/to/file.xml');
    echo "XML loaded successfully.";

    // Modify the XML as needed
    $xml->addChild('newElement', 'value');

    // Save the modified XML back to a file
    $xmlParser->save($xml, '/path/to/modified_file.xml');
    echo "XML saved successfully.";
} catch (Exception $e) {
    echo "Error handling XML: " . $e->getMessage();
}
```
