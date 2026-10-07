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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testTitleIsTheMainTitleWhenASubtitleComesFirst(): void
    {
        $metadata = $this->metadata(
            '<dc:title id="sub">The Subtitle</dc:title><meta refines="#sub" property="title-type">subtitle</meta>'
            . '<dc:title id="main">The Main Title</dc:title><meta refines="#main" property="title-type">main</meta>',
            withTitle: false
        );

        $this->assertSame('The Main Title', $metadata->getTitle());
        $this->assertSame(['The Subtitle', 'The Main Title'], $metadata->getTitles());

        $metadata->setTitle('Renamed');
        $metadata->save();

        $reloaded = $this->reloadMetadata();
        $this->assertSame(['The Subtitle', 'Renamed'], $reloaded->getTitles());
        $this->assertSame('Renamed', $reloaded->getTitle());
    }

    public function testSetTitlesReplacesAllTitlesInOrder(): void
    {
        $metadata = $this->metadata('', withTitle: false);
        $this->assertSame([], $metadata->getTitles());

        $metadata->setTitles(['First', 'Second']);
        $metadata->save();

        $reloaded = $this->reloadMetadata();
        $this->assertSame(['First', 'Second'], $reloaded->getTitles());
        $this->assertSame('First', $reloaded->getTitle());

        $reloaded->setTitles(['Only']);
        $this->assertSame(['Only'], $reloaded->getTitles());
    }

    public function testSetTitlesRequiresATitle(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('cannot be empty');

        $this->metadata('')->setTitles([]);
    }

    public function testDatesFollowEpub2Events(): void
    {
        $metadata = $this->metadata(
            '<dc:date xmlns:opf="http://www.idpf.org/2007/opf" opf:event="modification">2020-05-05</dc:date>'
            . '<dc:date xmlns:opf="http://www.idpf.org/2007/opf" opf:event="publication">1999-01-01</dc:date>'
            . '<dc:date xmlns:opf="http://www.idpf.org/2007/opf" opf:event="creation">1998-02-02</dc:date>'
        );

        $this->assertSame('1999-01-01', $metadata->getDate());
        $this->assertSame('2020-05-05', $metadata->getModifiedDate());
        $this->assertSame(
            ['modification' => '2020-05-05', 'publication' => '1999-01-01', 'creation' => '1998-02-02'],
            $metadata->getDateEvents()
        );

        $metadata->setDate('2001-01-01');
        $metadata->save();

        $reloaded = $this->reloadMetadata();
        $this->assertSame('2001-01-01', $reloaded->getDate());
        $this->assertSame('2020-05-05', $reloaded->getDateEvents()['modification']);
    }

    public function testDatesInEpub3(): void
    {
        $metadata = $this->metadata('<dc:date>2010-10-10</dc:date><meta property="dcterms:modified">2026-01-02T03:04:05Z</meta>');

        $this->assertSame('2010-10-10', $metadata->getDate());
        $this->assertSame('2026-01-02T03:04:05Z', $metadata->getModifiedDate());
        $this->assertSame(['' => '2010-10-10'], $metadata->getDateEvents());
    }

    public function testDatesWhenAbsent(): void
    {
        $metadata = $this->metadata('');

        $this->assertSame('', $metadata->getDate());
        $this->assertNull($metadata->getModifiedDate());
        $this->assertSame([], $metadata->getDateEvents());
    }

    public function testRepeatedMetasAsLists(): void
    {
        $metadata = $this->metadata(
            '<meta name="calibre:user_categories" content="A"/><meta name="calibre:user_categories" content="B"/>'
            . '<meta property="dcterms:subject">x</meta><meta property="dcterms:subject">y</meta>'
            . '<meta refines="#c1" property="dcterms:subject">refinement</meta>'
        );
        $this->assertSame(['A', 'B'], $metadata->getMetaValues('calibre:user_categories'));
        $this->assertSame(['x', 'y'], $metadata->getPropertyValues('dcterms:subject'));
        $this->assertSame([], $metadata->getMetaValues('missing'));

        $metadata->setMetaValues('calibre:user_categories', ['C', 'D', 'E']);
        $metadata->setPropertyValues('dcterms:subject', ['z']);
        $metadata->save();

        $reloaded = $this->reloadMetadata();
        $this->assertSame(['C', 'D', 'E'], $reloaded->getMetaValues('calibre:user_categories'));
        $this->assertSame(['z'], $reloaded->getPropertyValues('dcterms:subject'));

        $reloaded->setMetaValues('calibre:user_categories', []);
        $reloaded->setPropertyValues('dcterms:subject', []);
        $this->assertSame([], $reloaded->getMetaValues('calibre:user_categories'));
        $this->assertSame([], $reloaded->getPropertyValues('dcterms:subject'));
    }

    public function testSetMetaAndSetPropertyReplaceDuplicates(): void
    {
        $metadata = $this->metadata(
            '<meta name="calibre:series" content="Old"/><meta name="calibre:series" content="Older"/>'
            . '<meta property="belongs-to-collection">Old</meta><meta property="belongs-to-collection">Older</meta>'
        );

        $metadata->setMeta('calibre:series', 'New');
        $metadata->setProperty('belongs-to-collection', 'New');

        $this->assertSame(['New'], $metadata->getMetaValues('calibre:series'));
        $this->assertSame(['New'], $metadata->getPropertyValues('belongs-to-collection'));
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

    public function testGetCoverImageFromEpub2MetaNamingAnHref(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal()->withFile('EPUB/package.opf', EpubBuilder::opf(
            '<item id="img" href="images/cover.jpg" media-type="image/jpeg"/>',
            '<meta name="cover" content="images/cover.jpg"/>'
        )));

        $this->assertSame('img', $epubFile->getCoverImage()?->id);
    }

    public function testGetCoverImageFromGuideReferenceToAnImage(): void
    {
        $opf = str_replace('</package>', '<guide><reference type="cover" href="images/front.png"/></guide></package>', EpubBuilder::opf(
            '<item id="img" href="images/front.png" media-type="image/png"/>'
        ));

        $this->assertSame('img', $this->open(EpubBuilder::minimal()->withFile('EPUB/package.opf', $opf))->getCoverImage()?->id);
    }

    public function testGetCoverImageFromGuideReferenceToACoverPage(): void
    {
        $opf = str_replace('</package>', '<guide><reference type="cover" href="text/cover.xhtml#top"/></guide></package>', EpubBuilder::opf(
            '<item id="page" href="text/cover.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="img" href="images/front.jpg" media-type="image/jpeg"/>'
        ));
        $epubFile = $this->open(EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/text/cover.xhtml', EpubBuilder::xhtml('Cover', '<div><img src="../images/front.jpg" alt="Cover"/></div>')));

        $this->assertSame('img', $epubFile->getCoverImage()?->id);
    }

    public function testGetCoverImageFromAUtf16CoverPage(): void
    {
        $opf = str_replace('</package>', '<guide><reference type="cover" href="text/cover.xhtml"/></guide></package>', EpubBuilder::opf(
            '<item id="page" href="text/cover.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="img" href="images/front.jpg" media-type="image/jpeg"/>'
        ));
        $page = '<?xml version="1.0" encoding="UTF-16"?>' . EpubBuilder::xhtml('Обложка', '<div><img src="../images/front.jpg" alt="Обложка"/></div>');
        $epubFile = $this->open(EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/text/cover.xhtml', "\xFF\xFE" . mb_convert_encoding($page, 'UTF-16LE', 'UTF-8')));

        $this->assertSame('img', $epubFile->getCoverImage()?->id);
    }

    public function testGetCoverImageFromAnSvgCoverPageSkipsSourcesOutsideTheBook(): void
    {
        $opf = str_replace('</package>', '<guide><reference type="Cover" href="cover.xhtml"/></guide></package>', EpubBuilder::opf(
            '<item id="page" href="cover.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="img" href="images/front.jpg" media-type="image/jpeg"/>'
        ));
        $epubFile = $this->open(EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/cover.xhtml', EpubBuilder::xhtml(
                'Cover',
                '<img src="../../outside.jpg" alt=""/><img src="chapter.xhtml" alt=""/>'
                . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="images/front.jpg"/></svg>'
            )));

        $this->assertSame('img', $epubFile->getCoverImage()?->id);
    }

    /**
     * @param array<string, string> $files
     */
    #[DataProvider('guideCoversWithoutImage')]
    public function testGuideCoverWithoutUsableImageGivesNoCover(string $items, string $href, array $files): void
    {
        $builder = EpubBuilder::minimal()->withFile(
            'EPUB/package.opf',
            str_replace('</package>', "<guide><reference type=\"cover\" href=\"{$href}\"/></guide></package>", EpubBuilder::opf($items))
        );
        foreach ($files as $path => $content) {
            $builder->withFile($path, $content);
        }

        $this->assertNull($this->open($builder)->getCoverImage());
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function guideCoversWithoutImage(): iterable
    {
        yield 'not a page or image' => ['<item id="css" href="style.css" media-type="text/css"/>', 'style.css', ['EPUB/style.css' => 'p {}']];
        yield 'page file missing' => ['<item id="page" href="cover.xhtml" media-type="application/xhtml+xml"/>', 'cover.xhtml', []];
        yield 'page without images' => ['<item id="page" href="cover.xhtml" media-type="application/xhtml+xml"/>', 'cover.xhtml', ['EPUB/cover.xhtml' => '<html><body><p>Cover</p></body></html>']];
        yield 'reference to an unlisted file' => ['', 'nowhere.xhtml', []];
        yield 'reference outside the book' => ['', '../../outside.jpg', []];
    }

    /**
     * A book whose cover "old" (EPUB/old.jpg) is marked every way: property, meta and guide.
     */
    private function bookWithCover(): EpubBuilder
    {
        $opf = str_replace(
            '</package>',
            '<guide><reference type="cover" href="old.jpg"/><reference type="text" href="text.xhtml"/></guide></package>',
            EpubBuilder::opf(
                '<item id="old" href="old.jpg" media-type="image/jpeg" properties="cover-image"/>',
                '<meta name="cover" content="old"/>'
            )
        );

        return EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/old.jpg', (string) base64_decode(EpubBuilder::JPEG, true));
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

        $png = (string) base64_decode(EpubBuilder::PNG, true);
        $cover = $epubFile->setCoverImage($png, 'image/png');

        $this->assertSame('EPUB/images/cover.png', $cover->path);
        $this->assertSame('cover-image', $cover->properties);
        $epubFile->save($this->tmpDir . '/out.epub');

        $reloaded = EpubFile::open($this->tmpDir . '/out.epub');
        $this->assertSame('EPUB/images/cover.png', $reloaded->getCoverImage()?->path);
        $this->assertSame($png, $reloaded->getContentManager()->getContent('EPUB/images/cover.png'));
        $this->assertSame($cover->id, $reloaded->getMetadata()->getMeta('cover'));
        // The previous cover is no longer marked as the cover.
        $this->assertSame('', $reloaded->getManifest()->get('old')?->properties);
    }

    public function testSetCoverImageOnEpub2WritesOnlyTheMeta(): void
    {
        $opf = str_replace('version="3.0"', 'version="2.0"', EpubBuilder::opf());
        $epubFile = $this->open(EpubBuilder::minimal()->withFile('EPUB/package.opf', $opf));

        $cover = $epubFile->setCoverImage((string) base64_decode(EpubBuilder::JPEG, true), 'image/jpeg', 'EPUB/art/front.jpg');

        $this->assertSame('EPUB/art/front.jpg', $cover->path);
        $this->assertSame('', $cover->properties);
        $this->assertSame($cover->id, $epubFile->getMetadata()->getMeta('cover'));
        $this->assertSame('EPUB/art/front.jpg', $epubFile->getCoverImage()?->path);
    }

    public function testSetCoverImageUpdatesTheMediaTypeOfAReusedPath(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal()->withFile('EPUB/package.opf', EpubBuilder::opf(
            '<item id="art" href="art/cover.img" media-type="image/jpeg"/>'
        )));

        $cover = $epubFile->setCoverImage((string) base64_decode(EpubBuilder::PNG, true), 'image/png', 'EPUB/art/cover.img');

        $this->assertSame('art', $cover->id);
        $this->assertSame('image/png', $cover->mediaType);
        $this->assertSame('image/png', $epubFile->getManifest()->get('art')?->mediaType);
    }

    public function testSetCoverImageRejectsNonImages(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cover must be an image');

        $epubFile->setCoverImage('<html/>', 'application/xhtml+xml');
    }

    public function testSetCoverImageRejectsBytesOfAnotherFormat(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The cover data is image/png, not image/jpeg');

        $epubFile->setCoverImage((string) base64_decode(EpubBuilder::PNG, true), 'image/jpeg');
    }

    public function testSetCoverImageRejectsBytesThatAreNotTheDeclaredImage(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal());

        try {
            $epubFile->setCoverImage('not an image', 'image/png');
            $this->fail('Expected an exception for bytes that are not a PNG.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('is not a valid image/png image', $exception->getMessage());
        }

        // Nothing is written before the check.
        $this->assertNull($epubFile->getCoverImage());
        $this->assertNotContains('EPUB/images/cover.png', $epubFile->getContentManager()->getContentPaths());
    }

    public function testSetCoverImageTrustsFormatsItCannotDetect(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal());

        $cover = $epubFile->setCoverImage('<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml');

        $this->assertSame('image/svg+xml', $cover->mediaType);
    }

    public function testSetCoverImageCanDeleteThePreviousCover(): void
    {
        $epubFile = $this->open($this->bookWithCover());

        $cover = $epubFile->setCoverImage((string) base64_decode(EpubBuilder::PNG, true), 'image/png', null, true);

        $this->assertSame($cover->path, $epubFile->getCoverImage()?->path);
        $this->assertNull($epubFile->getManifest()->get('old'));
        $this->assertNotContains('EPUB/old.jpg', $epubFile->getContentManager()->getContentPaths());
    }

    public function testSetCoverImageKeepsThePreviousCoverWhenItIsTheSameFile(): void
    {
        $epubFile = $this->open($this->bookWithCover());

        $cover = $epubFile->setCoverImage((string) base64_decode(EpubBuilder::JPEG, true), 'image/jpeg', 'EPUB/old.jpg', true);

        $this->assertSame('old', $cover->id);
        $this->assertSame('EPUB/old.jpg', $epubFile->getCoverImage()?->path);
    }

    public function testRemoveCoverImageUnmarksTheCoverAndKeepsTheFile(): void
    {
        $epubFile = $this->open($this->bookWithCover());

        $epubFile->removeCoverImage();

        $this->assertNull($epubFile->getCoverImage());
        $this->assertSame('', $epubFile->getManifest()->get('old')?->properties);
        $this->assertNull($epubFile->getMetadata()->getMeta('cover'));
        $this->assertNull($epubFile->getManifest()->getGuidePath('cover'));
        $this->assertSame('EPUB/text.xhtml', $epubFile->getManifest()->getGuidePath('text'));
        $this->assertContains('EPUB/old.jpg', $epubFile->getContentManager()->getContentPaths());
    }

    public function testRemoveCoverImageCanDeleteTheFile(): void
    {
        $epubFile = $this->open($this->bookWithCover());

        $epubFile->removeCoverImage(true);

        $this->assertNull($epubFile->getCoverImage());
        $this->assertNull($epubFile->getManifest()->get('old'));
        $this->assertNotContains('EPUB/old.jpg', $epubFile->getContentManager()->getContentPaths());
    }

    public function testRemoveCoverImageDropsTheItemOfAMissingFile(): void
    {
        $epubFile = $this->open($this->bookWithCover()->withoutFile('EPUB/old.jpg'));

        $epubFile->removeCoverImage(true);

        $this->assertNull($epubFile->getManifest()->get('old'));
    }

    public function testRemoveCoverImageWithoutACoverDoesNothing(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal());

        $epubFile->removeCoverImage(true);

        $this->assertNull($epubFile->getCoverImage());
        $this->assertFalse($epubFile->getManifest()->isModified());
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

    private function metadata(string $extra, bool $withTitle = true): Metadata
    {
        $opf = EpubBuilder::opf(metadata: $extra);
        if (! $withTitle) {
            $opf = str_replace('<dc:title>Minimal</dc:title>', '', $opf);
        }

        file_put_contents($this->tmpDir . '/package.opf', $opf);

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
