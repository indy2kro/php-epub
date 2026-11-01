<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\EpubReader;
use PhpEpub\Exception;
use PhpEpub\ReadOnlyException;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ValidationIssue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Writing the page list: TableOfContents::setPageList() and generatePageList().
 */
final class PageListTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'page-list';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testSetPageListWritesAHiddenPageListNavAndKeepsTheToc(): void
    {
        $epub = $this->open($this->epub3WithPages());
        $toc = $epub->getTableOfContents();
        $entries = $toc->getEntries();
        $pages = [new TocEntry('1', 'EPUB/text/chapter.xhtml', 'page1'), new TocEntry('2', 'EPUB/text/chapter.xhtml', 'page2')];

        $toc->setPageList($pages);

        $this->assertEquals($pages, $toc->getPageList());
        $this->assertEquals($entries, $toc->getEntries());
        $nav = $epub->getContentManager()->getContent('EPUB/nav.xhtml');
        $this->assertMatchesRegularExpression('#<nav epub:type="page-list" hidden="">.*href="text/chapter.xhtml\#page2">2</a>#s', $nav);
        $this->assertSame([], $this->errors($epub));
    }

    public function testSetPageListWritesTheNcxPageListOfAnEpub2Book(): void
    {
        $epub = $this->open(EpubBuilder::epub2()->withFile('OEBPS/text/chapter.xhtml', $this->epub2Chapter()));
        $pages = [
            new TocEntry('ii', 'OEBPS/text/chapter.xhtml', 'front'),
            new TocEntry('1', 'OEBPS/text/chapter.xhtml', 'page1'),
            new TocEntry('12', 'OEBPS/text/chapter.xhtml', 'page2'),
            new TocEntry('Plate A', 'OEBPS/text/chapter.xhtml', 'plate'),
        ];

        $epub->getTableOfContents()->setPageList($pages);

        $this->assertEquals($pages, $epub->getTableOfContents()->getPageList());
        $ncx = $epub->getContentManager()->getContent('OEBPS/toc.ncx');
        $this->assertStringContainsString('</navMap><pageList>', (string) preg_replace('/>\s+</', '><', $ncx));
        $this->assertStringContainsString('<pageTarget id="page-1" type="front" value="2" playOrder="2">', $ncx);
        $this->assertStringContainsString('<pageTarget id="page-2" type="normal" value="1" playOrder="3">', $ncx);
        $this->assertStringContainsString('<pageTarget id="page-4" type="special" playOrder="5">', $ncx);
        $this->assertStringContainsString('<meta name="dtb:totalPageCount" content="4"/>', $ncx);
        $this->assertStringContainsString('<meta name="dtb:maxPageNumber" content="12"/>', $ncx);
        $this->assertSame([], $this->errors($epub));
    }

    public function testSetPageListReplacesAndRemovesThePageList(): void
    {
        $epub = $this->open(EpubBuilder::epub2()->withFile('OEBPS/text/chapter.xhtml', $this->epub2Chapter()));
        $toc = $epub->getTableOfContents();
        $toc->setPageList([new TocEntry('1', 'OEBPS/text/chapter.xhtml', 'page1')]);
        $toc->setPageList([new TocEntry('2', 'OEBPS/text/chapter.xhtml', 'page2')]);

        $this->assertEquals([new TocEntry('2', 'OEBPS/text/chapter.xhtml', 'page2')], $toc->getPageList());
        $this->assertSame(1, substr_count($epub->getContentManager()->getContent('OEBPS/toc.ncx'), '<pageList'));

        $toc->setPageList([]);

        $this->assertSame([], $toc->getPageList());
        $ncx = $epub->getContentManager()->getContent('OEBPS/toc.ncx');
        $this->assertStringNotContainsString('pageList', $ncx);
        $this->assertStringContainsString('<meta name="dtb:totalPageCount" content="0"/>', $ncx);

        $epub3 = $this->open($this->epub3WithPages());
        $epub3->getTableOfContents()->setPageList([new TocEntry('1', 'EPUB/text/chapter.xhtml', 'page1')]);
        $epub3->getTableOfContents()->setPageList([]);
        $this->assertStringNotContainsString('page-list', $epub3->getContentManager()->getContent('EPUB/nav.xhtml'));
    }

    public function testSetPageListNeedsANavigationDocumentOrNcx(): void
    {
        $opf = str_replace(' properties="nav"', '', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $epub = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf));

        $this->expectException(Exception::class);
        $epub->getTableOfContents()->setPageList([new TocEntry('1', 'EPUB/text/chapter.xhtml')]);
    }

    #[DataProvider('invalidPages')]
    public function testSetPageListRejectsInvalidPages(TocEntry $page): void
    {
        $epub = $this->open($this->epub3WithPages());
        $before = $epub->getContentManager()->getContent('EPUB/nav.xhtml');

        try {
            $epub->getTableOfContents()->setPageList([new TocEntry('1', 'EPUB/text/chapter.xhtml', 'page1'), $page]);
            $this->fail('The page was accepted.');
        } catch (Exception) {
            $this->assertSame($before, $epub->getContentManager()->getContent('EPUB/nav.xhtml'));
        }
    }

    /**
     * @return iterable<string, array{TocEntry}>
     */
    public static function invalidPages(): iterable
    {
        yield 'no number' => [new TocEntry(' ', 'EPUB/text/chapter.xhtml')];
        yield 'no path' => [new TocEntry('2', '')];
        yield 'children' => [new TocEntry('2', 'EPUB/text/chapter.xhtml', null, [new TocEntry('3', 'EPUB/text/chapter.xhtml')])];
        yield 'outside the book' => [new TocEntry('2', '../outside.xhtml')];
        yield 'not XML text' => [new TocEntry("2\x01", 'EPUB/text/chapter.xhtml')];
    }

    public function testGeneratePageListReadsThePageBreakMarkersInReadingOrder(): void
    {
        $chapter = EpubBuilder::xhtml('Chapter', '<p>Text<span epub:type="pagebreak" id="p1" title="1"/></p>'
            . '<p>More<span role="doc-pagebreak" aria-label=" 2 "/></p>'
            . '<div epub:type="pagebreak" id="p3">3</div><span epub:type="noteref pagebreak" id="toc-1">iv</span>'
            . '<span epub:type="pagebreak"></span>');
        $epub = $this->open(EpubBuilder::epub3()->withFile('EPUB/text/chapter.xhtml', $chapter));

        $pages = $epub->getTableOfContents()->generatePageList();

        $this->assertEquals([
            new TocEntry('1', 'EPUB/text/chapter.xhtml', 'p1'),
            new TocEntry('2', 'EPUB/text/chapter.xhtml', 'page-1'),
            new TocEntry('3', 'EPUB/text/chapter.xhtml', 'p3'),
            new TocEntry('iv', 'EPUB/text/chapter.xhtml', 'toc-1'),
        ], $pages);
        $this->assertEquals($pages, $epub->getTableOfContents()->getPageList());
        $this->assertStringContainsString('id="page-1"', $epub->getContentManager()->getContent('EPUB/text/chapter.xhtml'));
        $this->assertSame([], $this->errors($epub));
    }

    public function testGeneratePageListLeavesABookWithoutMarkersAlone(): void
    {
        $epub = $this->open(EpubBuilder::epub3());
        $before = $epub->getContentManager()->getContent('EPUB/nav.xhtml');

        $this->assertSame([], $epub->getTableOfContents()->generatePageList());
        $this->assertSame($before, $epub->getContentManager()->getContent('EPUB/nav.xhtml'));
    }

    public function testGeneratePageListNeedsTheSpine(): void
    {
        $epub = $this->open(EpubBuilder::epub3());
        $toc = new \PhpEpub\TableOfContents((string) $epub->getTempDir(), $epub->getManifest());

        $this->expectException(Exception::class);
        $toc->generatePageList();
    }

    public function testAReadOnlyBookCannotSetItsPageList(): void
    {
        $reader = EpubReader::open($this->epub3WithPages()->buildEpub($this->tmpDir . '/book.epub'));

        $this->expectException(ReadOnlyException::class);
        $reader->getTableOfContents()->setPageList([new TocEntry('1', 'EPUB/text/chapter.xhtml', 'page1')]);
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/' . uniqid('book', true) . '.epub'));
    }

    private function epub3WithPages(): EpubBuilder
    {
        return EpubBuilder::epub3()->withFile('EPUB/text/chapter.xhtml', EpubBuilder::xhtml(
            'Chapter',
            '<p><span epub:type="pagebreak" id="page1" title="1"/>One</p><p><span epub:type="pagebreak" id="page2" title="2"/>Two</p>'
        ));
    }

    private function epub2Chapter(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">'
            . '<head><title>Chapter</title><link rel="stylesheet" type="text/css" href="../css/style.css"/></head>'
            . '<body><h1>Chapter</h1><p id="front">ii</p><p id="page1">One</p><p id="page2">Two</p><p id="plate">A</p></body></html>';
    }

    /**
     * @return list<string>
     */
    private function errors(EpubFile $epub): array
    {
        return array_values(array_map(
            static fn (ValidationIssue $issue): string => (string) $issue,
            array_filter($epub->validate(), static fn (ValidationIssue $issue): bool => $issue->severity === 'error')
        ));
    }
}
