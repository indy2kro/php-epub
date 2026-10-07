<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PhpEpub\Metadata;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\XmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Generic access to the Dublin Core elements without dedicated accessors, and to every language.
 */
final class DublinCoreTest extends TestCase
{
    private string $tmpDir;

    private string $opfPath;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'dublin-core';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }

        $this->opfPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'package.opf';
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testReadsAndWritesAnyDublinCoreElement(): void
    {
        $metadata = $this->load('<dc:rights>All rights reserved</dc:rights><dc:source>urn:isbn:9780000000000</dc:source>');

        $this->assertSame(['All rights reserved'], $metadata->getDublinCoreValues('rights'));
        $this->assertSame([], $metadata->getDublinCoreValues('coverage'));

        $metadata->setDublinCoreValues('rights', ['CC BY 4.0']);
        $metadata->setDublinCoreValues('type', ['Text', 'Novel']);
        $metadata->setDublinCoreValues('source', []);
        $metadata->save();

        $reloaded = $this->reload();
        $this->assertSame(['CC BY 4.0'], $reloaded->getDublinCoreValues('rights'));
        $this->assertSame(['Text', 'Novel'], $reloaded->getDublinCoreValues('type'));
        $this->assertSame([], $reloaded->getDublinCoreValues('source'));
    }

    public function testRejectsNamesThatAreNotDublinCoreElements(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Not a Dublin Core element: series');

        $this->load('')->getDublinCoreValues('series');
    }

    public function testIdentifiersAreSetWithSetIdentifiers(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('setIdentifiers()');

        $this->load('')->setDublinCoreValues('identifier', ['urn:x']);
    }

    public function testRequiredElementsStayRequired(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('dc:title cannot be empty');

        $this->load('')->setDublinCoreValues('title', []);
    }

    public function testEveryLanguage(): void
    {
        $metadata = $this->load('<dc:language>fr</dc:language>');

        $this->assertSame(['en', 'fr'], $metadata->getLanguages());

        $metadata->setLanguages(['de', 'en']);

        $this->assertSame(['de', 'en'], $metadata->getLanguages());
        $this->assertSame('de', $metadata->getLanguage());
    }

    public function testABookNeedsALanguage(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('dc:language cannot be empty');

        $this->load('')->setLanguages([]);
    }

    private function load(string $metadata): Metadata
    {
        file_put_contents(
            $this->opfPath,
            '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="uid">urn:uuid:1</dc:identifier>'
            . "<dc:title>Book</dc:title><dc:language>en</dc:language>{$metadata}</metadata><manifest/><spine/></package>"
        );

        return $this->reload();
    }

    private function reload(): Metadata
    {
        return new Metadata((new XmlParser())->parse($this->opfPath), $this->opfPath);
    }
}
