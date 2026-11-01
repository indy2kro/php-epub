<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PhpEpub\Test\Support\UnreadableFile;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

final class XmlParserTest extends TestCase
{
    private string $xmlFilePath;
    private string $invalidXmlFilePath;
    private string $outputXmlFilePath;

    protected function setUp(): void
    {
        $this->xmlFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.xml';
        $this->invalidXmlFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'invalid.xml';
        $this->outputXmlFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'output.xml';

        // Ensure the output directory exists
        if (! is_dir(dirname($this->outputXmlFilePath))) {
            mkdir(dirname($this->outputXmlFilePath), 0777, true);
        }

        // Create a valid XML file for testing
        file_put_contents($this->xmlFilePath, '<root><element>Value</element></root>');

        // Create an invalid XML file for testing
        file_put_contents($this->invalidXmlFilePath, '<root><element>Value</element>');
    }

    protected function tearDown(): void
    {
        // Clean up any files created during tests
        if (file_exists($this->outputXmlFilePath)) {
            unlink($this->outputXmlFilePath);
        }

        if (file_exists($this->xmlFilePath)) {
            unlink($this->xmlFilePath);
        }

        if (file_exists($this->invalidXmlFilePath)) {
            unlink($this->invalidXmlFilePath);
        }
    }

    public function testParseRejectsEntityDeclarations(): void
    {
        file_put_contents($this->xmlFilePath, '<?xml version="1.0"?><!DOCTYPE root [<!ENTITY x "expanded">]><root>&x;</root>');

        $this->expectException(XmlException::class);
        $this->expectExceptionMessage('entity declarations');

        (new XmlParser())->parse($this->xmlFilePath);
    }

    public function testParseRejectsEntityDeclarationsInUtf16Documents(): void
    {
        // In UTF-16 every character is followed by a NUL byte, so a byte search for "<!ENTITY" misses it.
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE root [<!ENTITY x "expanded">]><root>&x;</root>';
        file_put_contents($this->xmlFilePath, "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));

        $this->expectException(XmlException::class);
        $this->expectExceptionMessage('entity declarations');

        (new XmlParser())->parse($this->xmlFilePath);
    }

    public function testParseStringAppliesTheSameProtections(): void
    {
        $parser = new XmlParser();

        $this->assertSame('Value', (string) $parser->parseString('<root>Value</root>'));

        $this->expectException(XmlException::class);
        $this->expectExceptionMessage('XML entity declarations are not allowed: inline SVG');

        $parser->parseString('<!DOCTYPE root [<!ENTITY a "b">]><root>&a;</root>', 'inline SVG');
    }

    public function testParseAcceptsUtf16DocumentsWithoutEntities(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-16"?><root><element>Value</element></root>';
        file_put_contents($this->xmlFilePath, "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));

        $this->assertSame('Value', (string) (new XmlParser())->parse($this->xmlFilePath)->element);
    }

    public function testParseReportsAnUnreadableFile(): void
    {
        $file = UnreadableFile::make($this->xmlFilePath);

        try {
            if (! $file->isUnreadable()) {
                $this->markTestSkipped('Unreadable files are readable here (e.g. running as root).');
            }

            $this->expectException(XmlException::class);
            $this->expectExceptionMessage('Failed to read XML file');

            (new XmlParser())->parse($this->xmlFilePath);
        } finally {
            $file->restore();
        }
    }

    public function testParseAcceptsPublicDoctypeWithoutEntities(): void
    {
        // EPUB 2 NCX files commonly carry a DOCTYPE; it must not be fetched or rejected.
        file_put_contents(
            $this->xmlFilePath,
            '<?xml version="1.0"?><!DOCTYPE ncx PUBLIC "-//NISO//DTD ncx 2005-1//EN" "http://www.daisy.org/z3986/2005/ncx-2005-1.dtd"><ncx><navMap/></ncx>'
        );

        $xml = (new XmlParser())->parse($this->xmlFilePath);

        $this->assertSame('ncx', $xml->getName());
    }

    public function testParseInvalidXmlReportsLibxmlError(): void
    {
        try {
            (new XmlParser())->parse($this->invalidXmlFilePath);
            $this->fail('Expected an exception for malformed XML.');
        } catch (XmlException $exception) {
            $this->assertStringContainsString('Failed to load XML file:', $exception->getMessage());
            $this->assertMatchesRegularExpression('/line \d+/', $exception->getMessage());
        }

        $this->assertSame([], libxml_get_errors());
    }

    public function testParseUnreadablePathThrowsException(): void
    {
        $this->expectException(XmlException::class);
        $this->expectExceptionMessage('Failed to read XML file:');

        // A directory exists but cannot be read as a file.
        (new XmlParser())->parse(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures');
    }

    public function testParseValidXml(): void
    {
        $parser = new XmlParser();
        $xml = $parser->parse($this->xmlFilePath);

        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
        $this->assertSame('Value', (string) $xml->element);
    }

    public function testParseInvalidXmlThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to load XML file:');

        $parser = new XmlParser();
        $parser->parse($this->invalidXmlFilePath);
    }

    public function testParseNonExistentXmlThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('XML file not found:');

        $parser = new XmlParser();
        $parser->parse(__DIR__ . DIRECTORY_SEPARATOR . 'nonexistent');
    }

    public function testSaveXml(): void
    {
        $parser = new XmlParser();
        $xml = new SimpleXMLElement('<root><element>New Value</element></root>');

        $parser->save($xml, $this->outputXmlFilePath);

        $this->assertFileExists($this->outputXmlFilePath);
        $savedXml = simplexml_load_file($this->outputXmlFilePath);
        $this->assertNotFalse($savedXml);
        $this->assertSame('New Value', (string) $savedXml->element);
    }

    public function testSaveNonExistentXmlThrowsException(): void
    {
        $parser = new XmlParser();
        $xml = new SimpleXMLElement('<root><element>New Value</element></root>');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to save XML file:');
        $parser->save($xml, __DIR__ . DIRECTORY_SEPARATOR . 'nonexistent' . DIRECTORY_SEPARATOR . 'output.xml');
    }

    public function testSaveReplacesAFileWholeAndLeavesNoTemporaryFile(): void
    {
        $directory = dirname($this->outputXmlFilePath);
        file_put_contents($this->outputXmlFilePath, str_repeat('old ', 1000));
        chmod($this->outputXmlFilePath, 0640);
        $mode = fileperms($this->outputXmlFilePath) & 0777;
        $before = scandir($directory);

        (new XmlParser())->save(new SimpleXMLElement('<root><element>New</element></root>'), $this->outputXmlFilePath);

        $this->assertSame("<?xml version=\"1.0\"?>\n<root><element>New</element></root>\n", file_get_contents($this->outputXmlFilePath));
        $this->assertSame($mode, fileperms($this->outputXmlFilePath) & 0777, 'the file keeps its permissions');
        $this->assertSame($before, scandir($directory), 'no temporary file is left behind');
    }

    public function testSaveRefusesAReadOnlyFileAndLeavesItAsItWas(): void
    {
        file_put_contents($this->outputXmlFilePath, '<root>old</root>');
        chmod($this->outputXmlFilePath, 0444);
        $directory = dirname($this->outputXmlFilePath);
        $before = scandir($directory);

        try {
            if (is_writable($this->outputXmlFilePath)) {
                $this->markTestSkipped('The process can write read-only files (e.g. running as root).');
            }

            (new XmlParser())->save(new SimpleXMLElement('<root>new</root>'), $this->outputXmlFilePath);
            $this->fail('No exception was thrown.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Failed to save XML file:', $exception->getMessage());
        } finally {
            chmod($this->outputXmlFilePath, 0644);
        }

        $this->assertSame('<root>old</root>', file_get_contents($this->outputXmlFilePath));
        $this->assertSame($before, scandir($directory));
    }

    public function testSaveRefusesADirectory(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to save XML file:');
        (new XmlParser())->save(new SimpleXMLElement('<root/>'), dirname($this->outputXmlFilePath));
    }

    public function testWithNameNamesTheFileInErrorsAndLeavesTheParserAsItIs(): void
    {
        $parser = new XmlParser(1000);
        $named = $parser->withName('EPUB/package.opf');

        $this->assertNotSame($parser, $named);
        $this->assertSame(1000, $named->maxBytes);
        $calls = [
            fn () => $named->parse($this->invalidXmlFilePath),
            static fn () => $named->save(new SimpleXMLElement('<root/>'), __DIR__ . DIRECTORY_SEPARATOR . 'nonexistent' . DIRECTORY_SEPARATOR . 'output.xml'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('No exception was thrown.');
            } catch (Exception $exception) {
                $this->assertStringContainsString('EPUB/package.opf', $exception->getMessage());
                $this->assertStringNotContainsString(__DIR__, $exception->getMessage());
            }
        }

        $this->expectExceptionMessage($this->invalidXmlFilePath);
        $parser->parse($this->invalidXmlFilePath);
    }

    public function testASubclassWithTheOriginalSignaturesKeepsWorkingThroughWithName(): void
    {
        // A subclass written against the original parse() and save() signatures still loads, and withName() keeps it.
        $parser = new class extends XmlParser {
            /** @var list<string> */
            public array $calls = [];

            public function parse(string $filePath): SimpleXMLElement
            {
                $this->calls[] = 'parse';

                return parent::parse($filePath);
            }

            public function save(SimpleXMLElement $xml, string $filePath): void
            {
                $this->calls[] = 'save';
                parent::save($xml, $filePath);
            }
        };

        $named = $parser->withName('name.xml');
        $named->save($named->parse($this->xmlFilePath), $this->outputXmlFilePath);

        $this->assertInstanceOf($parser::class, $named);
        $this->assertSame(['parse', 'save'], $named->calls);
    }
}
