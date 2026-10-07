<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PhpEpub\Metadata;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\XmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Series metadata in its two conventions: EPUB 3 belongs-to-collection and Calibre's metas.
 */
final class SeriesTest extends TestCase
{
    private string $tmpDir;

    private string $opfPath;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'series';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }

        $this->opfPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'package.opf';
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testReadsTheEpub3SeriesCollection(): void
    {
        $metadata = $this->load('3.0', '<meta property="belongs-to-collection" id="set">Box Set</meta>'
            . '<meta property="belongs-to-collection" id="c1">The Expanse</meta>'
            . '<meta refines="#c1" property="collection-type">series</meta><meta refines="#c1" property="group-position">2.5</meta>'
            . '<meta name="calibre:series" content="Old Name"/><meta name="calibre:series_index" content="9"/>');

        $this->assertSame('The Expanse', $metadata->getSeries());
        $this->assertSame('2.5', $metadata->getSeriesIndex());
    }

    public function testFallsBackToCalibreMetas(): void
    {
        $metadata = $this->load('2.0', '<meta name="calibre:series" content=" Discworld "/><meta name="calibre:series_index" content="3"/>');

        $this->assertSame('Discworld', $metadata->getSeries());
        $this->assertSame('3', $metadata->getSeriesIndex());
    }

    public function testBookWithoutSeries(): void
    {
        $metadata = $this->load('3.0', '<meta property="belongs-to-collection" id="set">Box Set</meta>');

        $this->assertNull($metadata->getSeries());
        $this->assertNull($metadata->getSeriesIndex());
    }

    public function testSetSeriesWritesBothConventionsInEpub3(): void
    {
        $metadata = $this->load('3.0', '<meta property="belongs-to-collection" id="set">Box Set</meta>'
            . '<meta property="belongs-to-collection" id="c1">Old</meta><meta refines="#c1" property="collection-type">series</meta>'
            . '<meta refines="#c1" property="group-position">1</meta>');

        $metadata->setSeries('The Expanse', 2);
        $metadata->save();

        $reloaded = $this->reload();
        $this->assertSame('The Expanse', $reloaded->getSeries());
        $this->assertSame('2', $reloaded->getSeriesIndex());
        $this->assertSame('The Expanse', $reloaded->getMeta('calibre:series'));
        $this->assertSame('2', $reloaded->getMeta('calibre:series_index'));
        // Other collections stay; the old series and its refinements are gone.
        $this->assertSame(['Box Set', 'The Expanse'], $reloaded->getPropertyValues('belongs-to-collection'));
        $this->assertStringNotContainsString('#c1', (string) file_get_contents($this->opfPath));
    }

    public function testSetSeriesWritesCalibreMetasInEpub2(): void
    {
        $metadata = $this->load('2.0', '');

        $metadata->setSeries('Discworld', '1.5');
        $metadata->save();

        $reloaded = $this->reload();
        $this->assertSame('Discworld', $reloaded->getSeries());
        $this->assertSame('1.5', $reloaded->getSeriesIndex());
        $this->assertSame([], $reloaded->getPropertyValues('belongs-to-collection'));
    }

    public function testSetSeriesWithoutIndexRemovesTheOldIndex(): void
    {
        $metadata = $this->load('3.0', '<meta name="calibre:series" content="Old"/><meta name="calibre:series_index" content="4"/>');

        $metadata->setSeries('New');

        $this->assertSame('New', $metadata->getSeries());
        $this->assertNull($metadata->getSeriesIndex());
        $this->assertNull($metadata->getMeta('calibre:series_index'));
    }

    public function testSetSeriesNullRemovesTheSeries(): void
    {
        $metadata = $this->load('3.0', '<meta property="belongs-to-collection" id="c1">Old</meta>'
            . '<meta refines="#c1" property="collection-type">series</meta>'
            . '<meta name="calibre:series" content="Old"/><meta name="calibre:series_index" content="4"/>');

        $metadata->setSeries(null);

        $this->assertNull($metadata->getSeries());
        $this->assertNull($metadata->getSeriesIndex());
        $this->assertSame([], $metadata->getPropertyValues('belongs-to-collection'));
    }

    public function testSetSeriesRejectsAnEmptyName(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The series name cannot be empty');

        $this->load('3.0', '')->setSeries(' ');
    }

    public function testSetSeriesRejectsAnIndexThatIsNotANumber(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The series index must be a number, got: second');

        $this->load('3.0', '')->setSeries('Series', 'second');
    }

    private function load(string $version, string $metadata): Metadata
    {
        file_put_contents(
            $this->opfPath,
            '<?xml version="1.0" encoding="UTF-8"?>'
            . "<package xmlns=\"http://www.idpf.org/2007/opf\" version=\"{$version}\" unique-identifier=\"uid\">"
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
