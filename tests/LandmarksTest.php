<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Landmark;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LandmarksTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'landmarks';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testReadsTheLandmarksNavOfAnEpub3Book(): void
    {
        $nav = '<nav epub:type="toc"><h1>Contents</h1><ol><li><a href="text/chapter.xhtml">Chapter</a></li></ol></nav>'
            . '<nav epub:type="landmarks" hidden=""><h2>Guide</h2><ol>'
            . '<li><a epub:type="cover" href="text/chapter.xhtml">Cover</a></li>'
            . '<li><a epub:type="bodymatter chapter" href="text/chapter.xhtml#start">Start &amp; go</a></li>'
            . '<li><a epub:type="x" href="https://example.com/">Remote</a></li>'
            . '<li><a href="text/chapter.xhtml">Untyped</a></li>'
            . '</ol></nav>';
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml('Contents', $nav)));

        $this->assertEquals(
            [
                new Landmark('cover', 'Cover', 'EPUB/text/chapter.xhtml'),
                new Landmark('bodymatter chapter', 'Start & go', 'EPUB/text/chapter.xhtml', 'start'),
            ],
            $epubFile->getTableOfContents()->getLandmarks()
        );
    }

    public function testReadsTheGuideOfAnEpub2BookAsLandmarks(): void
    {
        $guide = '<guide><reference type="cover" title="Front" href="text/chapter.xhtml"/>'
            . '<reference type="text" title="Start" href="text/chapter.xhtml#s%201"/>'
            . '<reference type="other.unknown" title="Odd" href="text/chapter.xhtml"/>'
            . '<reference type="toc" title="Outside" href="../../outside.xhtml"/></guide>';
        $epubFile = $this->open($this->epub2WithGuide($guide));

        $this->assertEquals(
            [
                new Landmark('cover', 'Front', 'OEBPS/text/chapter.xhtml'),
                new Landmark('bodymatter', 'Start', 'OEBPS/text/chapter.xhtml', 's 1'),
            ],
            $epubFile->getTableOfContents()->getLandmarks()
        );
    }

    public function testAnEpub3BookWithoutALandmarksNavFallsBackToItsGuide(): void
    {
        $opf = str_replace('</package>', '<guide><reference type="toc" title="Contents" href="nav.xhtml"/></guide></package>', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf));

        $this->assertEquals([new Landmark('toc', 'Contents', 'EPUB/nav.xhtml')], $epubFile->getTableOfContents()->getLandmarks());
    }

    public function testSetLandmarksCreatesTheLandmarksNavAndKeepsTheToc(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $landmarks = [
            new Landmark('cover', 'Cover', 'EPUB/text/chapter.xhtml'),
            new Landmark('bodymatter', 'Start <here>', 'EPUB/text/chapter.xhtml', 'a b'),
        ];

        $epubFile->getTableOfContents()->setLandmarks($landmarks);
        $epubFile->save($this->tmpDir . '/out.epub');

        $reopened = EpubFile::open($this->tmpDir . '/out.epub');
        $toc = $reopened->getTableOfContents();
        $this->assertEquals($landmarks, $toc->getLandmarks());
        $this->assertEquals([new TocEntry('Chapter', 'EPUB/text/chapter.xhtml')], $toc->getEntries());
        $this->assertSame([], $reopened->getManifest()->getGuideReferences(), 'An EPUB 3 book without a guide does not get one.');
        $this->assertSame([], array_map(strval(...), $reopened->validate()));

        $nav = (string) $reopened->getContentManager()->getContent('EPUB/nav.xhtml');
        $this->assertStringContainsString('<nav epub:type="landmarks" hidden="">', $nav);
        $this->assertStringContainsString('<a epub:type="bodymatter" href="text/chapter.xhtml#a%20b">Start &lt;here&gt;</a>', $nav);
    }

    public function testSetLandmarksReplacesTheLandmarksNav(): void
    {
        $nav = '<nav epub:type="toc"><ol><li><a href="text/chapter.xhtml">Chapter</a></li></ol></nav>'
            . '<nav epub:type="landmarks"><ol><li><a epub:type="cover" href="text/chapter.xhtml">Old</a></li></ol></nav>';
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml('Contents', $nav)));

        $epubFile->getTableOfContents()->setLandmarks([new Landmark('toc', 'Contents', 'EPUB/nav.xhtml')]);

        $this->assertEquals([new Landmark('toc', 'Contents', 'EPUB/nav.xhtml')], $epubFile->getTableOfContents()->getLandmarks());
        $this->assertSame(1, substr_count($epubFile->getContentManager()->getContent('EPUB/nav.xhtml'), 'landmarks'));
    }

    public function testSetLandmarksToNothingRemovesTheNavAndTheGuide(): void
    {
        $nav = '<nav epub:type="toc"><ol><li><a href="text/chapter.xhtml">Chapter</a></li></ol></nav>'
            . '<nav epub:type="landmarks"><ol><li><a epub:type="cover" href="text/chapter.xhtml">Old</a></li></ol></nav>';
        $opf = str_replace('</package>', '<guide><reference type="cover" title="Old" href="text/chapter.xhtml"/></guide></package>', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml('Contents', $nav))->withFile('EPUB/package.opf', $opf));

        $epubFile->getTableOfContents()->setLandmarks([]);

        $this->assertSame([], $epubFile->getTableOfContents()->getLandmarks());
        $this->assertSame([], $epubFile->getManifest()->getGuideReferences());
        $this->assertStringNotContainsString('landmarks', $epubFile->getContentManager()->getContent('EPUB/nav.xhtml'));

        // Removing what is not there is fine too.
        $epubFile->getTableOfContents()->setLandmarks([]);
        $this->assertSame([], $epubFile->getTableOfContents()->getLandmarks());
    }

    public function testSetLandmarksWritesTheGuideOfAnEpub2BookAfterTheSpine(): void
    {
        $epubFile = $this->open($this->epub2WithGuide(''));

        $epubFile->getTableOfContents()->setLandmarks([
            new Landmark('cover', 'Cover', 'OEBPS/text/chapter.xhtml'),
            new Landmark('bodymatter', 'Start', 'OEBPS/text/chapter.xhtml', 'top'),
            new Landmark('chapter', 'Not in the guide', 'OEBPS/text/chapter.xhtml'),
        ]);
        $epubFile->save($this->tmpDir . '/out.epub');

        $reopened = EpubFile::open($this->tmpDir . '/out.epub');
        $this->assertEquals(
            [new Landmark('cover', 'Cover', 'OEBPS/text/chapter.xhtml'), new Landmark('text', 'Start', 'OEBPS/text/chapter.xhtml', 'top')],
            $reopened->getManifest()->getGuideReferences()
        );
        $opf = $reopened->getContentManager()->getContent('OEBPS/content.opf');
        $this->assertMatchesRegularExpression('#</spine>\s*<guide>.*</guide>\s*</package>#s', $opf);
        $this->assertStringContainsString('href="text/chapter.xhtml#top"', $opf);
    }

    public function testSetLandmarksUpdatesTheGuideAnEpub3BookKeeps(): void
    {
        $opf = str_replace('</package>', '<guide><reference type="cover" title="Old" href="text/chapter.xhtml"/></guide></package>', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf));

        $epubFile->getTableOfContents()->setLandmarks([new Landmark('toc', 'Contents', 'EPUB/nav.xhtml')]);

        $this->assertEquals([new Landmark('toc', 'Contents', 'EPUB/nav.xhtml')], $epubFile->getManifest()->getGuideReferences());
        $this->assertEquals([new Landmark('toc', 'Contents', 'EPUB/nav.xhtml')], $epubFile->getTableOfContents()->getLandmarks());
        $this->assertStringContainsString('epub:type="toc" href="nav.xhtml"', $epubFile->getContentManager()->getContent('EPUB/nav.xhtml'));
    }

    public function testSetLandmarksNeedsAPlaceToWriteThem(): void
    {
        $opf = (string) preg_replace('#<item id="nav"[^>]*/>#', '', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('no navigation document or guide');
        $epubFile->getTableOfContents()->setLandmarks([new Landmark('cover', 'Cover', 'EPUB/text/chapter.xhtml')]);
    }

    #[DataProvider('invalidLandmarks')]
    public function testSetLandmarksRejectsInvalidLandmarks(Landmark $landmark): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());

        $this->expectException(Exception::class);
        $epubFile->getTableOfContents()->setLandmarks([$landmark]);
    }

    /**
     * @return iterable<string, array{Landmark}>
     */
    public static function invalidLandmarks(): iterable
    {
        yield 'empty type' => [new Landmark(' ', 'Cover', 'EPUB/text/chapter.xhtml')];
        yield 'empty title' => [new Landmark('cover', '', 'EPUB/text/chapter.xhtml')];
        yield 'empty path' => [new Landmark('cover', 'Cover', '')];
        yield 'path outside the book' => [new Landmark('cover', 'Cover', '../outside.xhtml')];
        yield 'invalid XML text' => [new Landmark('cover', "Co\x01ver", 'EPUB/text/chapter.xhtml')];
    }

    public function testMapsGuideTypesToEpub3TypesAndBack(): void
    {
        $this->assertSame('bodymatter', Landmark::fromGuideType('TEXT'));
        $this->assertSame('titlepage', Landmark::fromGuideType('title-page'));
        $this->assertNull(Landmark::fromGuideType('other.custom'));
        $this->assertSame('text', Landmark::toGuideType('bodymatter'));
        $this->assertSame('acknowledgements', Landmark::toGuideType('acknowledgments'));
        $this->assertNull(Landmark::toGuideType('chapter'));
    }

    public function testReadsThePageListOfTheNavigationDocument(): void
    {
        $nav = '<nav epub:type="toc"><ol><li><a href="text/chapter.xhtml">Chapter</a></li></ol></nav>'
            . '<nav epub:type="page-list"><ol><li><a href="text/chapter.xhtml#p1">1</a></li><li><a href="text/chapter.xhtml#p2">2</a></li></ol></nav>';
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml('Contents', $nav)));

        $this->assertEquals(
            [new TocEntry('1', 'EPUB/text/chapter.xhtml', 'p1'), new TocEntry('2', 'EPUB/text/chapter.xhtml', 'p2')],
            $epubFile->getTableOfContents()->getPageList()
        );
    }

    public function testReadsThePageListOfTheNcx(): void
    {
        $ncx = '<?xml version="1.0" encoding="UTF-8"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head/><docTitle><text>T</text></docTitle>'
            . '<navMap><navPoint id="p" playOrder="1"><navLabel><text>C</text></navLabel><content src="text/chapter.xhtml"/></navPoint></navMap>'
            . '<pageList><navLabel><text>Pages</text></navLabel>'
            . '<pageTarget id="pt1" type="normal" value="1"><navLabel><text>i</text></navLabel><content src="text/chapter.xhtml#p1"/></pageTarget>'
            . '<pageTarget id="pt2" type="normal" value="2"><content src="text/chapter.xhtml"/></pageTarget></pageList></ncx>';
        $epubFile = $this->open($this->epub2WithGuide('')->withFile('OEBPS/toc.ncx', $ncx));

        $this->assertEquals(
            [new TocEntry('i', 'OEBPS/text/chapter.xhtml', 'p1'), new TocEntry('', 'OEBPS/text/chapter.xhtml')],
            $epubFile->getTableOfContents()->getPageList()
        );
    }

    public function testThePageListIsEmptyWithoutOne(): void
    {
        $this->assertSame([], $this->open(EpubBuilder::epub3())->getTableOfContents()->getPageList());
        $this->assertSame([], $this->open($this->epub2WithGuide(''))->getTableOfContents()->getPageList());

        $withoutNavigation = (string) preg_replace('#<item id="nav"[^>]*/>#', '', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $this->assertSame([], $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $withoutNavigation))->getTableOfContents()->getPageList());
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }

    private function epub2WithGuide(string $guide): EpubBuilder
    {
        $book = EpubBuilder::epub2();
        $opf = (string) preg_replace('#<guide>.*</guide>#s', $guide, (string) $book->getFile('OEBPS/content.opf'));

        return $book->withFile('OEBPS/content.opf', $opf);
    }
}
