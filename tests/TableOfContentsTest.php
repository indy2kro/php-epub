<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Manifest;
use PhpEpub\TableOfContents;
use PhpEpub\TocEntry;
use PhpEpub\XmlParser;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

final class TableOfContentsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'toc';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testReadsTheNestedEpub3Navigation(): void
    {
        $epubFile = EpubFile::open(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub');

        $entries = $epubFile->getTableOfContents()->getEntries();

        $this->assertSame('Cover page', $entries[0]->title);
        $this->assertSame('EPUB/xhtml/cover.xhtml', $entries[0]->path);
        $this->assertNull($entries[0]->fragment);
        $this->assertSame([], $entries[0]->children);
        $this->assertSame('Basic Functionality Tests', $entries[3]->title);
        $this->assertCount(4, $entries[3]->children);
        $this->assertSame('EPUB/xhtml/Basic-functionality-tests.xhtml', $entries[3]->children[0]->path);
        $this->assertSame('file-010', $entries[3]->children[0]->fragment);
        $this->assertSame('Operating system/Platform accessibility', $entries[3]->children[0]->title);
    }

    public function testReadsTheEpub2Ncx(): void
    {
        $toc = $this->open($this->epub2Book())->getTableOfContents();

        $this->assertEquals(
            [
                new TocEntry('Chapter One', 'OEBPS/text/one.xhtml', null, [
                    new TocEntry('Section & More', 'OEBPS/text/one.xhtml', 's1'),
                ]),
                new TocEntry('Chapter Two', 'OEBPS/text/two.xhtml'),
            ],
            $toc->getEntries()
        );
    }

    public function testSetEntriesRewritesNavAndNcxAndKeepsTheRest(): void
    {
        $epubFile = $this->open($this->epub3BookWithNcx());
        $entries = [
            new TocEntry('Opening <& Close>', 'EPUB/text/one.xhtml', null, [
                new TocEntry('Detail', 'EPUB/text/one.xhtml', 'detail'),
            ]),
            new TocEntry('Part without a link'),
            new TocEntry('Ending', 'EPUB/end.xhtml'),
        ];

        $epubFile->getTableOfContents()->setEntries($entries);
        $epubFile->save($this->tmpDir . '/out.epub');

        $reopened = EpubFile::open($this->tmpDir . '/out.epub');
        $this->assertEquals($entries, $reopened->getTableOfContents()->getEntries());

        $nav = $reopened->getContentManager()->getContent('EPUB/text/nav.xhtml');
        $this->assertStringContainsString('<h1>Contents</h1>', $nav);
        $this->assertStringContainsString('href="one.xhtml#detail"', $nav);
        $this->assertStringContainsString('href="../end.xhtml"', $nav);
        $this->assertStringContainsString('<span>Part without a link</span>', $nav);
        $this->assertStringContainsString('epub:type="landmarks"', $nav);

        $ncx = $reopened->getContentManager()->getContent('EPUB/toc.ncx');
        $this->assertStringContainsString('src="text/one.xhtml#detail"', $ncx);
        $this->assertStringContainsString('playOrder="3"', $ncx);
        $this->assertStringNotContainsString('Old', $ncx);
        // An entry without a link cannot be an NCX navPoint; its children move up a level instead.
        $this->assertStringNotContainsString('Part without a link', $ncx);
    }

    public function testAddEntryAppendsATopLevelEntry(): void
    {
        $epubFile = $this->open($this->epub3BookWithNcx());
        $toc = $epubFile->getTableOfContents();

        $toc->addEntry(new TocEntry('Appendix', 'EPUB/text/one.xhtml', 'appendix'));

        $titles = array_map(static fn (TocEntry $entry): string => $entry->title, $toc->getEntries());
        $this->assertSame(['Old One', 'Appendix'], $titles);
    }

    public function testEntriesPointingOutsideTheBookHaveNoPath(): void
    {
        $builder = $this->epub3BookWithNcx()->withFile('EPUB/text/nav.xhtml', EpubBuilder::xhtml(
            'Nav',
            '<nav epub:type="toc"><ol><li><a href="../../../outside.xhtml">Escape</a></li><li><a href="https://example.com/">Remote</a></li></ol></nav>'
        ));

        $entries = $this->open($builder)->getTableOfContents()->getEntries();

        $this->assertEquals([new TocEntry('Escape'), new TocEntry('Remote')], $entries);
    }

    public function testSetEntriesRejectsPathsOutsideTheBook(): void
    {
        $toc = $this->open($this->epub3BookWithNcx())->getTableOfContents();

        $this->expectException(Exception::class);

        $toc->setEntries([new TocEntry('Escape', '../outside.xhtml')]);
    }

    public function testBookWithoutNavigation(): void
    {
        $toc = $this->open(EpubBuilder::minimal())->getTableOfContents();

        $this->assertSame([], $toc->getEntries());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('has no navigation document or NCX');

        $toc->setEntries([new TocEntry('Chapter', 'EPUB/chapter.xhtml')]);
    }

    public function testReadingSpanHeadingsSameDocumentLinksAndUnlabelledItems(): void
    {
        $builder = $this->epub3BookWithNcx()->withFile('EPUB/text/nav.xhtml', EpubBuilder::xhtml(
            'Nav',
            '<nav epub:type="toc"><ol>'
            . '<li><span>Part I</span><ol><li><a href="one.xhtml#s%201">Spaced Anchor</a></li></ol></li>'
            . '<li><a href="#top">This Page</a></li>'
            . '<li>No label</li>'
            . '</ol></nav>'
        ));

        $entries = $this->open($builder)->getTableOfContents()->getEntries();

        $this->assertEquals(
            [
                new TocEntry('Part I', '', null, [new TocEntry('Spaced Anchor', 'EPUB/text/one.xhtml', 's 1')]),
                new TocEntry('This Page', 'EPUB/text/nav.xhtml', 'top'),
            ],
            $entries
        );
    }

    public function testSetEntriesCreatesTheTocNavWhenMissing(): void
    {
        $builder = $this->epub3BookWithNcx()
            ->withFile('EPUB/text/nav.xhtml', EpubBuilder::xhtml('Nav', '<nav epub:type="landmarks"><ol><li><a href="one.xhtml">Start</a></li></ol></nav>'));
        $epubFile = $this->open($builder);
        $this->assertSame([], $epubFile->getTableOfContents()->getEntries());
        $entries = [new TocEntry('One', 'EPUB/text/one.xhtml')];

        $epubFile->getTableOfContents()->setEntries($entries);

        $this->assertEquals($entries, $epubFile->getTableOfContents()->getEntries());
        $nav = $epubFile->getContentManager()->getContent('EPUB/text/nav.xhtml');
        $this->assertStringContainsString('epub:type="landmarks"', $nav);
        $this->assertStringContainsString('<nav epub:type="toc"><ol><li><a href="one.xhtml">One</a></li></ol></nav>', $nav);
    }

    public function testSetEntriesCreatesTheNcxNavMapWhenMissing(): void
    {
        // Parser rejects such an NCX when a book is loaded, so build the objects directly.
        $directory = $this->epub2Book()
            ->withFile('OEBPS/toc.ncx', '<?xml version="1.0" encoding="UTF-8"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head/></ncx>')
            ->writeTo($this->tmpDir . '/book');
        $manifest = new Manifest((new XmlParser())->parse($directory . '/OEBPS/content.opf'), 'OEBPS/content.opf');
        $toc = new TableOfContents($directory, $manifest);
        $this->assertSame([], $toc->getEntries());
        $this->assertNull($toc->getBook());

        $toc->setEntries([new TocEntry('One', 'OEBPS/text/one.xhtml')]);

        $this->assertStringContainsString(
            '<navMap><navPoint id="navPoint-1" playOrder="1"><navLabel><text>One</text></navLabel><content src="text/one.xhtml"/></navPoint></navMap>',
            (string) file_get_contents($directory . '/OEBPS/toc.ncx')
        );
    }

    public function testSetEntriesAddsAListToATocNavWithout(): void
    {
        $builder = $this->epub3BookWithNcx()->withFile('EPUB/text/nav.xhtml', EpubBuilder::xhtml('Nav', '<nav epub:type="toc"><h1>Contents</h1></nav>'));
        $epubFile = $this->open($builder);

        $epubFile->getTableOfContents()->setEntries([new TocEntry('One', 'EPUB/text/one.xhtml')]);

        $this->assertStringContainsString(
            '<h1>Contents</h1><ol><li><a href="one.xhtml">One</a></li></ol>',
            $epubFile->getContentManager()->getContent('EPUB/text/nav.xhtml')
        );
    }

    public function testNcxOnlyBookIsWrittenToTheNcx(): void
    {
        $epubFile = $this->open($this->epub2Book());
        $entries = [new TocEntry('Only', 'OEBPS/text/two.xhtml')];

        $epubFile->getTableOfContents()->setEntries($entries);

        $this->assertEquals($entries, $epubFile->getTableOfContents()->getEntries());
    }

    public function testSetEntriesRejectsInvalidTitles(): void
    {
        $toc = $this->open($this->epub3BookWithNcx())->getTableOfContents();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not valid XML text');

        $toc->setEntries([new TocEntry('Fine', 'EPUB/end.xhtml', null, [new TocEntry("Bad\x01")])]);
    }

    public function testSetEntriesReportsANavigationFileItCannotWrite(): void
    {
        $epubFile = $this->open($this->epub3BookWithNcx());
        $navFile = $epubFile->getTempDir() . DIRECTORY_SEPARATOR . 'EPUB' . DIRECTORY_SEPARATOR . 'text' . DIRECTORY_SEPARATOR . 'nav.xhtml';
        chmod($navFile, 0444);

        try {
            if (is_writable($navFile)) {
                $this->markTestSkipped('Read-only files are writable here (e.g. running as root).');
            }

            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Failed to write the table of contents to: EPUB/text/nav.xhtml');

            $epubFile->getTableOfContents()->setEntries([new TocEntry('One', 'EPUB/text/one.xhtml')]);
        } finally {
            chmod($navFile, 0644);
        }
    }

    public function testTheTableOfContentsKeepsItsBookAlive(): void
    {
        $path = $this->epub3BookWithNcx()->buildEpub($this->tmpDir . '/alive.epub');

        // No variable holds the EpubFile; its extracted files must outlive the expression.
        $toc = EpubFile::open($path)->getTableOfContents();

        $this->assertSame('Old One', $toc->getEntries()[0]->title);
    }

    public function testTableOfContentsBeforeLoadThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('EPUB file must be loaded before accessing the table of contents.');

        (new EpubFile($this->tmpDir . '/missing.epub'))->getTableOfContents();
    }

    public function testDeletingAFileRemovesItsEntries(): void
    {
        $epubFile = $this->open($this->epub2Book());

        $epubFile->getContentManager()->deleteContent('OEBPS/text/one.xhtml');

        $this->assertEquals([new TocEntry('Chapter Two', 'OEBPS/text/two.xhtml')], $epubFile->getTableOfContents()->getEntries());
        $this->assertSame([], $epubFile->validate());
    }

    public function testDeletingAFileKeepsTheChildrenOfItsEntries(): void
    {
        $epubFile = $this->open($this->epub3BookWithNcx());
        $toc = $epubFile->getTableOfContents();
        $toc->setEntries([new TocEntry('Part', 'EPUB/text/one.xhtml', 'detail', [new TocEntry('End', 'EPUB/end.xhtml')])]);

        $epubFile->getContentManager()->deleteContent('EPUB/text/one.xhtml');

        // The navigation document keeps the entry as an unlinked heading; the NCX, which has none, promotes its children.
        $this->assertEquals([new TocEntry('Part', '', null, [new TocEntry('End', 'EPUB/end.xhtml')])], $toc->getEntries());
        $ncx = (string) file_get_contents($epubFile->getTempDir() . '/EPUB/toc.ncx');
        $this->assertStringNotContainsString('one.xhtml', $ncx);
        $this->assertStringContainsString('end.xhtml', $ncx);
    }

    public function testDeletingAFileOutsideTheTableOfContentsLeavesItUntouched(): void
    {
        $epubFile = $this->open($this->epub3BookWithNcx());
        $navPath = $epubFile->getTempDir() . '/EPUB/text/nav.xhtml';
        $nav = (string) file_get_contents($navPath);

        $epubFile->getContentManager()->deleteContent('EPUB/end.xhtml');

        $this->assertSame($nav, file_get_contents($navPath));
    }

    public function testDeletingAFileWorksWhenTheNavigationDocumentIsBroken(): void
    {
        $epubFile = $this->open($this->epub3BookWithNcx());
        file_put_contents($epubFile->getTempDir() . '/EPUB/text/nav.xhtml', '<html><body><nav');

        $epubFile->getContentManager()->deleteContent('EPUB/text/one.xhtml');

        $this->assertFileDoesNotExist($epubFile->getTempDir() . '/EPUB/text/one.xhtml');
        $this->assertSame('<html><body><nav', file_get_contents($epubFile->getTempDir() . '/EPUB/text/nav.xhtml'));
    }

    public function testSavingANewTitleUpdatesTheNcxDocTitle(): void
    {
        $epubFile = $this->open($this->epub2Book());

        $epubFile->getMetadata()->setTitle('Renamed & Co');
        $epubFile->save();

        $ncx = $epubFile->getContentManager()->getContent('OEBPS/toc.ncx');
        $this->assertStringContainsString('<docTitle><text>Renamed &amp; Co</text></docTitle>', $ncx);
    }

    public function testSavingANewTitleAddsAMissingNcxDocTitle(): void
    {
        $builder = $this->epub2Book();
        $builder->withFile('OEBPS/toc.ncx', str_replace('<docTitle><text>Two</text></docTitle>', '', (string) $builder->getFile('OEBPS/toc.ncx')));
        $epubFile = $this->open($builder);

        $epubFile->getMetadata()->setTitle('Renamed');
        $epubFile->save();

        $ncx = $epubFile->getContentManager()->getContent('OEBPS/toc.ncx');
        $this->assertStringContainsString('<head/><docTitle><text>Renamed</text></docTitle><navMap>', $ncx);
    }

    public function testSavingOtherMetadataLeavesTheNcxUntouched(): void
    {
        $epubFile = $this->open($this->epub2Book());
        $ncx = $epubFile->getContentManager()->getContent('OEBPS/toc.ncx');

        $epubFile->getMetadata()->setDescription('About');
        $epubFile->save();

        $this->assertSame($ncx, $epubFile->getContentManager()->getContent('OEBPS/toc.ncx'));
    }

    public function testSavingANewTitleSkipsABrokenNcx(): void
    {
        $epubFile = $this->open($this->epub2Book());
        file_put_contents($epubFile->getTempDir() . '/OEBPS/toc.ncx', '<ncx');

        $epubFile->getMetadata()->setTitle('Renamed');
        $epubFile->save();

        $this->assertSame('<ncx', $epubFile->getContentManager()->getContent('OEBPS/toc.ncx'));
        $this->assertStringContainsString('<dc:title>Renamed</dc:title>', $epubFile->getContentManager()->getContent('OEBPS/content.opf'));
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }

    private function epub3BookWithNcx(): EpubBuilder
    {
        $opf = str_replace(
            '<spine>',
            '<spine toc="ncx">',
            EpubBuilder::opf(
                '<item id="nav" href="text/nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
                . '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
                . '<item id="one" href="text/one.xhtml" media-type="application/xhtml+xml"/>'
                . '<item id="end" href="end.xhtml" media-type="application/xhtml+xml"/>'
            )
        );

        return EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/text/nav.xhtml', EpubBuilder::xhtml(
                'Nav',
                '<nav epub:type="toc"><h1>Contents</h1><ol><li><a href="one.xhtml">Old One</a></li></ol></nav>'
                . '<nav epub:type="landmarks"><ol><li><a epub:type="bodymatter" href="one.xhtml">Start</a></li></ol></nav>'
            ))
            ->withFile('EPUB/text/one.xhtml', EpubBuilder::xhtml('One', '<h1 id="detail">One</h1>'))
            ->withFile('EPUB/end.xhtml', EpubBuilder::xhtml('End', '<p>End</p>'))
            ->withFile('EPUB/toc.ncx', '<?xml version="1.0" encoding="UTF-8"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">'
                . '<head/><docTitle><text>Book</text></docTitle><navMap><navPoint id="old" playOrder="1"><navLabel><text>Old</text></navLabel>'
                . '<content src="text/one.xhtml"/></navPoint></navMap></ncx>');
    }

    private function epub2Book(): EpubBuilder
    {
        $opf = '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="uid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="uid">urn:x</dc:identifier><dc:title>Two</dc:title><dc:language>en</dc:language></metadata>'
            . '<manifest><item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
            . '<item id="one" href="text/one.xhtml" media-type="application/xhtml+xml"/><item id="two" href="text/two.xhtml" media-type="application/xhtml+xml"/></manifest>'
            . '<spine toc="ncx"><itemref idref="one"/><itemref idref="two"/></spine></package>';
        $ncx = '<?xml version="1.0" encoding="UTF-8"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head/><docTitle><text>Two</text></docTitle><navMap>'
            . '<navPoint id="p1" playOrder="1"><navLabel><text>Chapter One</text></navLabel><content src="text/one.xhtml"/>'
            . '<navPoint id="p2" playOrder="2"><navLabel><text>Section &amp; More</text></navLabel><content src="text/one.xhtml#s1"/></navPoint></navPoint>'
            . '<navPoint id="p3" playOrder="3"><navLabel><text>Chapter Two</text></navLabel><content src="text/two.xhtml"/></navPoint>'
            . '</navMap></ncx>';

        return (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('OEBPS/content.opf')
            ->withFile('OEBPS/content.opf', $opf)
            ->withFile('OEBPS/toc.ncx', $ncx)
            ->withFile('OEBPS/text/one.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>1</title></head><body><p>1</p></body></html>')
            ->withFile('OEBPS/text/two.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>2</title></head><body><p>2</p></body></html>');
    }
}
