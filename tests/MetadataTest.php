<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PhpEpub\Metadata;
use PhpEpub\XmlParser;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class MetadataTest extends TestCase
{
    private string $tempOpfFilePath;
    private SimpleXMLElement $opfXml;

    protected function setUp(): void
    {
        $opfFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.opf';
        $this->tempOpfFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'valid.opf';

        // Ensure the temp directory exists
        if (! is_dir(dirname($this->tempOpfFilePath))) {
            mkdir(dirname($this->tempOpfFilePath), 0777, true);
        }

        // Copy the valid OPF file to a temporary location for testing
        copy($opfFilePath, $this->tempOpfFilePath);

        $xmlParser = new XmlParser();
        $this->opfXml = $xmlParser->parse($this->tempOpfFilePath);
    }

    protected function tearDown(): void
    {
        // Clean up the temporary OPF file after tests
        if (file_exists($this->tempOpfFilePath)) {
            unlink($this->tempOpfFilePath);
        }
    }

    public function testConstructorThrowsExceptionWhenDcNamespaceMissing(): void
    {
        $opfXml = new SimpleXMLElement('<package xmlns="http://www.idpf.org/2007/opf"></package>');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to identify dc namespace');

        new Metadata($opfXml);
    }

    public function testSaveThrowsExceptionWhenFileCannotBeSaved(): void
    {
        $metadata = new Metadata($this->opfXml);

        // Set the file path to an invalid directory to simulate a failure
        $invalidFilePath = '/invalid/path/opf.opf';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to save OPF file:');

        @$metadata->save($invalidFilePath);
    }

    public function testGetTitle(): void
    {
        $metadata = new Metadata($this->opfXml);
        $title = $metadata->getTitle();
        $this->assertSame('Fundamental Accessibility Tests: Basic Functionality', $title);
    }

    public function testSetTitle(): void
    {
        $metadata = new Metadata($this->opfXml);
        $metadata->setTitle('New Title');
        $metadata->save($this->tempOpfFilePath);

        $updatedMetadata = new Metadata($this->opfXml);
        $this->assertSame('New Title', $updatedMetadata->getTitle());
    }

    public function testGetDate(): void
    {
        $metadata = new Metadata($this->opfXml);
        $date = $metadata->getDate();
        $this->assertSame('', $date);
    }

    public function testSetDate(): void
    {
        $metadata = new Metadata($this->opfXml);
        $metadata->setDate('2022-01-01');
        $metadata->save($this->tempOpfFilePath);

        $updatedMetadata = new Metadata($this->opfXml);
        $this->assertSame('2022-01-01', $updatedMetadata->getDate());
    }

    public function testGetAuthors(): void
    {
        $metadata = new Metadata($this->opfXml);
        $authors = $metadata->getAuthors();
        $this->assertSame(['DAISY Consortium'], $authors);
    }

    public function testSetAuthors(): void
    {
        $metadata = new Metadata($this->opfXml);
        $metadata->setAuthors(['New Author']);
        $metadata->save($this->tempOpfFilePath);

        $updatedMetadata = new Metadata($this->opfXml);
        $this->assertSame(['New Author'], $updatedMetadata->getAuthors());
    }

    public function testGetPublisher(): void
    {
        $metadata = new Metadata($this->opfXml);
        $publisher = $metadata->getPublisher();
        $this->assertSame('Creative Commons', $publisher);
    }

    public function testSetPublisher(): void
    {
        $metadata = new Metadata($this->opfXml);
        $metadata->setPublisher('New Publisher');
        $metadata->save($this->tempOpfFilePath);

        $updatedMetadata = new Metadata($this->opfXml);
        $this->assertSame('New Publisher', $updatedMetadata->getPublisher());
    }

    public function testGetLanguage(): void
    {
        $metadata = new Metadata($this->opfXml);
        $language = $metadata->getLanguage();
        $this->assertSame('en', $language);
    }

    public function testSetLanguage(): void
    {
        $metadata = new Metadata($this->opfXml);
        $metadata->setLanguage('fr');
        $metadata->save($this->tempOpfFilePath);

        $updatedMetadata = new Metadata($this->opfXml);
        $this->assertSame('fr', $updatedMetadata->getLanguage());
    }

    public function testGetDescription(): void
    {
        $metadata = new Metadata($this->opfXml);
        $description = $metadata->getDescription();
        $this->assertSame('These tests include starting the reading system and opening the titles, navigating the content, searching, and using bookmarks and notes.', $description);
    }

    public function testSetDescription(): void
    {
        $metadata = new Metadata($this->opfXml);
        $metadata->setDescription('new description');
        $metadata->save($this->tempOpfFilePath);

        $updatedMetadata = new Metadata($this->opfXml);
        $this->assertSame('new description', $updatedMetadata->getDescription());
    }

    public function testGetSubject(): void
    {
        $metadata = new Metadata($this->opfXml);
        $subject = $metadata->getSubject();
        $this->assertSame('basic-functionality', $subject);
    }

    public function testSetSubject(): void
    {
        $metadata = new Metadata($this->opfXml);
        $metadata->setSubject('new subject');
        $metadata->save($this->tempOpfFilePath);

        $updatedMetadata = new Metadata($this->opfXml);
        $this->assertSame('new subject', $updatedMetadata->getSubject());
    }

    public function testGetIdentifier(): void
    {
        $metadata = new Metadata($this->opfXml);
        $identifiers = $metadata->getIdentifiers();
        $this->assertSame(['com.github.epub-testsuite.epub30-test-0301-2.0.0', '9781003410126'], $identifiers);
    }

    public function testSetIdentifier(): void
    {
        $metadata = new Metadata($this->opfXml);
        $metadata->setIdentifiers(['new identifier']);
        $metadata->save($this->tempOpfFilePath);

        $updatedMetadata = new Metadata($this->opfXml);
        $this->assertSame(['new identifier'], $updatedMetadata->getIdentifiers());
    }
}
