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
        // suppress warnings intedended
        @$parser->save($xml, __DIR__ . DIRECTORY_SEPARATOR . 'nonexistent' . DIRECTORY_SEPARATOR . 'output.xml');
    }
}
