# Parser

The `Parser` class in the PHP EPUB Processor library is responsible for parsing the structure of an EPUB file.
It validates the essential components of the EPUB, such as the mimetype, OPF, and NCX files,
ensuring that the EPUB file is correctly structured and ready for further processing.

## Key Methods

- **`__construct(XmlParser $xmlParser = new XmlParser())`**: Initializes the `Parser` with an `XmlParser` instance, which is used for XML parsing tasks.

- **`parse(string $directory): string`**: Parses the EPUB file structure within the specified directory. It validates the mimetype, extracts the OPF path from the container, and validates the OPF file. Returns the path to the OPF file.

- **`validateMimetype(string $directory): void`**: Validates the presence and content of the mimetype file, ensuring it is correctly set to `application/epub+zip`.

- **`extractOpfPath(string $containerPath): string`**: Extracts the OPF file path from the `container.xml` file, which defines the location of the OPF file within the EPUB.

- **`validateOpf(string $opfPath): void`**: Validates the OPF file and checks for the presence of the NCX file, ensuring the EPUB's manifest and navigation are correctly defined.

- **`validateNcx(string $ncxPath): void`**: Validates the NCX file, ensuring it contains a valid `navMap` element for navigation.

## Usage Example

```php
use PhpEpub\Parser;
use PhpEpub\XmlParser;

$directory = '/path/to/extracted/epub';
$parser = new Parser(new XmlParser());

try {
    $opfPath = $parser->parse($directory);
    echo "OPF file located at: $opfPath";
} catch (Exception $e) {
    echo "Error parsing EPUB: " . $e->getMessage();
}
```
