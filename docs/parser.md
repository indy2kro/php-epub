# Parser

The `Parser` class locates the package document (OPF) of an extracted EPUB and checks that it can be read. `EpubFile` uses it when loading a book.
Container and package documents are matched by namespace URI, so prefixed elements (e.g. `<opf:package>`) work as well.

## Methods

- **`__construct(XmlParser $xmlParser = new XmlParser(), PathResolver $paths = new PathResolver())`**: The `XmlParser` reads the container and package documents with its protections against hostile XML.

- **`parse(string $directory): string`**: Returns the OPF path relative to `$directory` (with `/` separators). The path comes from `META-INF/container.xml`; when the container lists several rootfiles (e.g. another rendition), the one with media type `application/oebps-package+xml` is used.

  It throws an `InvalidEpubException` only when the book has no readable package: no container or rootfile, an OPF path outside the book, or an OPF that is not XML, not in the OPF namespace or has no manifest. Problems that reading systems tolerate do not stop a book from loading: a missing or wrong `mimetype` file and a broken NCX are reported by [`EpubFile::validate()`](epub-file.md#validating) instead (`MIMETYPE_INVALID`, `NCX_INVALID`), and `EpubFile::save()` writes the right `mimetype`.

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
