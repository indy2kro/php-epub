<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
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
