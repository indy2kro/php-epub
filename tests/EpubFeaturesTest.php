<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Converters\ConverterInterface;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Metadata;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\XmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Generic metadata, cover images, and the EpubFile convenience API.
 */
final class EpubFeaturesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'features';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testEpub2NamedMetaRoundTrip(): void
    {
        $metadata = $this->metadata('<meta name="calibre:series" content="Saga"/>');
        $this->assertSame('Saga', $metadata->getMeta('calibre:series'));
        $this->assertNull($metadata->getMeta('calibre:series_index'));

        $metadata->setMeta('calibre:series', 'Saga & Sequel');
        $metadata->setMeta('calibre:series_index', '2');
        $metadata->save();

        $reloaded = $this->reloadMetadata();
        $this->assertSame('Saga & Sequel', $reloaded->getMeta('calibre:series'));
        $this->assertSame('2', $reloaded->getMeta('calibre:series_index'));

        $reloaded->setMeta('calibre:series', null);
        $this->assertNull($reloaded->getMeta('calibre:series'));
    }

    public function testEpub3PropertyRoundTripIgnoresRefinements(): void
    {
        $metadata = $this->metadata(
            '<dc:creator id="c1">Ann</dc:creator><meta refines="#c1" property="role">aut</meta>'
            . '<meta property="belongs-to-collection">Saga</meta>'
        );
        $this->assertSame('Saga', $metadata->getProperty('belongs-to-collection'));
        // A refinement describes another element; it is not a book-level property.
        $this->assertNull($metadata->getProperty('role'));

        $metadata->setProperty('belongs-to-collection', 'Other Saga');
        $metadata->setProperty('schema:accessMode', 'textual');
        $metadata->save();

        $reloaded = $this->reloadMetadata();
        $this->assertSame('Other Saga', $reloaded->getProperty('belongs-to-collection'));
        $this->assertSame('textual', $reloaded->getProperty('schema:accessMode'));
        $this->assertFalse($reloaded->isModified());

        $reloaded->setProperty('belongs-to-collection', null);
        $this->assertNull($reloaded->getProperty('belongs-to-collection'));
        $this->assertTrue($reloaded->isModified());
    }

    public function testGetCoverImageFromEpub3Property(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal()->withFile('EPUB/package.opf', EpubBuilder::opf(
            '<item id="img" href="images/front.jpg" media-type="image/jpeg" properties="cover-image"/>'
        )));

        $this->assertSame('EPUB/images/front.jpg', $epubFile->getCoverImage()?->path);
    }

    public function testGetCoverImageFromEpub2Meta(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal()->withFile('EPUB/package.opf', EpubBuilder::opf(
            '<item id="cover-jpg" href="cover.jpg" media-type="image/jpeg"/>',
            '<meta name="cover" content="cover-jpg"/>'
        )));

        $this->assertSame('EPUB/cover.jpg', $epubFile->getCoverImage()?->path);
    }

    public function testBookWithoutCover(): void
    {
        $this->assertNull($this->open(EpubBuilder::minimal())->getCoverImage());
    }

    public function testSetCoverImageReplacesTheCoverAndSurvivesSave(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal()->withFile('EPUB/package.opf', EpubBuilder::opf(
            '<item id="old" href="old.jpg" media-type="image/jpeg" properties="cover-image"/>',
            '<meta name="cover" content="old"/>'
        )));

        $cover = $epubFile->setCoverImage('PNGDATA', 'image/png');

        $this->assertSame('EPUB/images/cover.png', $cover->path);
        $this->assertSame('cover-image', $cover->properties);
        $epubFile->save($this->tmpDir . '/out.epub');

        $reloaded = EpubFile::open($this->tmpDir . '/out.epub');
        $this->assertSame('EPUB/images/cover.png', $reloaded->getCoverImage()?->path);
        $this->assertSame('PNGDATA', $reloaded->getContentManager()->getContent('EPUB/images/cover.png'));
        $this->assertSame($cover->id, $reloaded->getMetadata()->getMeta('cover'));
        // The previous cover is no longer marked as the cover.
        $this->assertSame('', $reloaded->getManifest()->get('old')?->properties);
    }

    public function testSetCoverImageOnEpub2WritesOnlyTheMeta(): void
    {
        $opf = str_replace('version="3.0"', 'version="2.0"', EpubBuilder::opf());
        $epubFile = $this->open(EpubBuilder::minimal()->withFile('EPUB/package.opf', $opf));

        $cover = $epubFile->setCoverImage('JPEG', 'image/jpeg', 'EPUB/art/front.jpg');

        $this->assertSame('EPUB/art/front.jpg', $cover->path);
        $this->assertSame('', $cover->properties);
        $this->assertSame($cover->id, $epubFile->getMetadata()->getMeta('cover'));
        $this->assertSame('EPUB/art/front.jpg', $epubFile->getCoverImage()?->path);
    }

    public function testSetCoverImageRejectsNonImages(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cover must be an image');

        $epubFile->setCoverImage('<html/>', 'application/xhtml+xml');
    }

    public function testOpenLoadsTheBook(): void
    {
        $path = EpubBuilder::minimal()->buildEpub($this->tmpDir . '/in.epub');

        $this->assertSame('Minimal', EpubFile::open($path)->getMetadata()->getTitle());
    }

    public function testConvertWritesPendingChangesBeforeConverting(): void
    {
        $epubFile = EpubFile::open(EpubBuilder::minimal()->buildEpub($this->tmpDir . '/in.epub'));
        $epubFile->getMetadata()->setTitle('Changed before converting');

        $adapter = new class () implements ConverterInterface {
            public string $seenTitle = '';

            public function convert(string $epubDirectory, string $outputPath): void
            {
                $opf = (new XmlParser())->parse($epubDirectory . '/EPUB/package.opf');
                $this->seenTitle = (new Metadata($opf, $epubDirectory . '/EPUB/package.opf'))->getTitle();
                file_put_contents($outputPath, 'converted');
            }
        };

        $epubFile->convert($adapter, $this->tmpDir . '/out.pdf');

        $this->assertSame('Changed before converting', $adapter->seenTitle);
        $this->assertStringEqualsFile($this->tmpDir . '/out.pdf', 'converted');
    }

    public function testConvertBeforeLoadThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('EPUB file must be loaded before converting.');

        (new EpubFile($this->tmpDir . '/missing.epub'))->convert($this->createStub(ConverterInterface::class), $this->tmpDir . '/out.pdf');
    }

    private function metadata(string $extra): Metadata
    {
        file_put_contents($this->tmpDir . '/package.opf', EpubBuilder::opf(metadata: $extra));

        return $this->reloadMetadata();
    }

    private function reloadMetadata(): Metadata
    {
        $path = $this->tmpDir . '/package.opf';

        return new Metadata((new XmlParser())->parse($path), $path);
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }
}
