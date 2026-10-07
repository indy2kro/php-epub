<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\TableOfContents;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ValidationIssue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Round 4, theme 1: well-formed XHTML, empty tables of contents, case-only moves, reference
 * rewriting on moves and generating a table of contents from headings.
 */
final class ContentIntegrityTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'integrity';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testAddChapterConvertsHtmlMarkupToXhtml(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());

        $item = $epubFile->addChapter('Loose', '<p>a&nbsp;b</p><p>line<br>break<p>unclosed <b>bold<hr><img src=x.png alt=pic>');

        $content = $epubFile->getContentManager()->getContent($item->path);
        $this->assertStringContainsString("<p>a\u{A0}b</p>", $content);
        $this->assertStringContainsString('<br/>', $content);
        $this->assertStringContainsString('<hr/>', $content);
        $this->assertStringContainsString('<img src="x.png" alt="pic"/>', $content);
        $this->assertNotFalse(simplexml_load_string($content));
    }

    public function testAddChapterKeepsWellFormedBodiesUnchanged(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $body = '<h1 id="a">Fish &amp; chips</h1><p>caf&#233;<br/></p><![CDATA[x < y]]>';

        $item = $epubFile->addChapter('Fine', $body);

        $this->assertStringContainsString('<body>' . $body . '</body>', $epubFile->getContentManager()->getContent($item->path));
    }

    public function testBodiesDeclaringEntitiesAreConvertedNotExpanded(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());

        $item = $epubFile->addChapter('Entities', '<!DOCTYPE x [<!ENTITY e "boom">]><p>&e;</p>');

        $this->assertStringNotContainsString('boom', $epubFile->getContentManager()->getContent($item->path));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function xhtmlPaths(): \Iterator
    {
        yield 'xhtml' => ['EPUB/new.xhtml'];
        yield 'html' => ['EPUB/new.HTML'];
        yield 'htm' => ['EPUB/new.htm'];
    }

    #[DataProvider('xhtmlPaths')]
    public function testAddContentRefusesMalformedXhtml(string $path): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $contentManager = $epubFile->getContentManager();

        try {
            $contentManager->addContent($path, '<html xmlns="http://www.w3.org/1999/xhtml"><body><p>a&nbsp;b<br></body></html>');
            $this->fail('Expected the malformed XHTML to be refused.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('not well-formed XML', $exception->getMessage());
        }

        $this->assertNotContains($path, $contentManager->getContentPaths());
    }

    public function testUpdateContentRefusesMalformedXhtmlAndKeepsTheFile(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $contentManager = $epubFile->getContentManager();
        $before = $contentManager->getContent('EPUB/text/chapter.xhtml');

        try {
            $contentManager->updateContent('EPUB/text/chapter.xhtml', '<html><body><p>Broken');
            $this->fail('Expected the malformed XHTML to be refused.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('not well-formed XML', $exception->getMessage());
        }

        $this->assertSame($before, $contentManager->getContent('EPUB/text/chapter.xhtml'));
    }

    public function testManifestMediaTypeMakesAnyPathXhtml(): void
    {
        $opf = str_replace(
            '<item id="style"',
            '<item id="odd" href="odd.dat" media-type="application/xhtml+xml"/><item id="style"',
            (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
        );
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf)->withFile('EPUB/odd.dat', '<html/>'));

        $this->expectException(Exception::class);

        $epubFile->getContentManager()->updateContent('EPUB/odd.dat', '<p>');
    }

    public function testEntityDeclarationsAreRefusedInXhtml(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());

        $this->expectException(Exception::class);

        $epubFile->getContentManager()->addContent('EPUB/e.xhtml', '<!DOCTYPE html [<!ENTITY e "x">]><html xmlns="http://www.w3.org/1999/xhtml"/>');
    }

    public function testOtherFilesAreNotCheckedForWellFormedness(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $contentManager = $epubFile->getContentManager();

        $contentManager->addContent('EPUB/css/other.css', 'p {');
        $contentManager->addContent('EPUB/notes.txt', '<p>');

        $this->assertSame('p {', $contentManager->getContent('EPUB/css/other.css'));
    }

    public function testSetEntriesRefusesAnEmptyList(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $toc = $epubFile->getTableOfContents();

        try {
            $toc->setEntries([]);
            $this->fail('Expected an exception.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('at least one entry', $exception->getMessage());
        }

        $this->assertCount(1, $toc->getEntries());
    }

    public function testDeletingTheLastLinkedFileEmptiesTheEpub3TocAndValidateReportsIt(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());

        $epubFile->getContentManager()->deleteContent('EPUB/text/chapter.xhtml');

        $this->assertSame([], $epubFile->getTableOfContents()->getEntries());
        $this->assertContains('NAV_EMPTY', $this->codes($epubFile->validate()));
    }

    public function testDeletingTheLastLinkedFileEmptiesTheNcxAndValidateReportsIt(): void
    {
        $epubFile = $this->open(EpubBuilder::epub2());

        $epubFile->getContentManager()->deleteContent('OEBPS/text/chapter.xhtml');

        $this->assertSame([], $epubFile->getTableOfContents()->getEntries());
        $this->assertContains('NCX_EMPTY', $this->codes($epubFile->validate()));
        $this->assertStringContainsString('<meta name="dtb:depth" content="0"/>', $epubFile->getContentManager()->getContent('OEBPS/toc.ncx'));
    }

    public function testMovingTheNavigationDocumentOfAnEmptyTocWorks(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $content = $epubFile->getContentManager();
        $content->deleteContent('EPUB/text/chapter.xhtml');

        $content->moveContent('EPUB/nav.xhtml', 'EPUB/navigation/toc.xhtml');

        $this->assertSame([], $epubFile->getTableOfContents()->getEntries());
    }

    public function testTheNcxDepthFollowsTheEntries(): void
    {
        $epubFile = $this->open(EpubBuilder::epub2());
        $toc = $epubFile->getTableOfContents();
        $depth = static fn (): string => preg_match(
            '/name="dtb:depth" content="(\d+)"/',
            $epubFile->getContentManager()->getContent('OEBPS/toc.ncx'),
            $match
        ) === 1 ? $match[1] : '';

        $toc->setEntries([
            new TocEntry('A', 'OEBPS/text/chapter.xhtml', null, [
                new TocEntry('B', 'OEBPS/text/chapter.xhtml', 'b', [new TocEntry('C', 'OEBPS/text/chapter.xhtml', 'c')]),
            ]),
        ]);
        $this->assertSame('3', $depth());

        $toc->setEntries([new TocEntry('A', 'OEBPS/text/chapter.xhtml')]);
        $this->assertSame('1', $depth());
    }

    public function testTheNcxDepthMetaIsCreatedWhenMissingAndNotWithoutAHead(): void
    {
        $ncx = (string) EpubBuilder::epub2()->getFile('OEBPS/toc.ncx');
        $withoutMeta = str_replace('<meta name="dtb:depth" content="1"/>', '', $ncx);
        $withoutHead = (string) preg_replace('#<head>.*?</head>#s', '', $ncx);
        $entries = [new TocEntry('A', 'OEBPS/text/chapter.xhtml')];

        $epubFile = $this->open(EpubBuilder::epub2()->withFile('OEBPS/toc.ncx', $withoutMeta));
        $epubFile->getTableOfContents()->setEntries($entries);
        $this->assertStringContainsString('<meta name="dtb:depth" content="1"/></head>', $epubFile->getContentManager()->getContent('OEBPS/toc.ncx'));

        $epubFile = $this->open(EpubBuilder::epub2()->withFile('OEBPS/toc.ncx', $withoutHead));
        $epubFile->getTableOfContents()->setEntries($entries);
        $this->assertStringNotContainsString('dtb:depth', $epubFile->getContentManager()->getContent('OEBPS/toc.ncx'));
    }

    public function testValidateReportsAnEmptyTocListAndNavMap(): void
    {
        $nav = EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><h1>Contents</h1><ol></ol></nav><nav epub:type="landmarks"><ol><li><a href="text/chapter.xhtml">x</a></li></ol></nav>');
        $codes = $this->codes($this->open(EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', $nav))->validate());
        $this->assertSame(['NAV_EMPTY'], $codes);

        $ncx = (string) preg_replace('#<navPoint.*</navPoint>#s', '', (string) EpubBuilder::epub2()->getFile('OEBPS/toc.ncx'));
        $codes = $this->codes($this->open(EpubBuilder::epub2()->withFile('OEBPS/toc.ncx', $ncx))->validate());
        $this->assertSame(['NCX_EMPTY'], $codes);
    }

    public function testMovingAChapterRewritesLinksStylesheetsAndTheMovedDocumentsOwnReferences(): void
    {
        $epubFile = $this->open($this->linkedBook());
        $content = $epubFile->getContentManager();

        $content->moveContent('EPUB/text/ch1.xhtml', 'EPUB/ch1.xhtml');

        $two = $content->getContent('EPUB/text/ch2.xhtml');
        $this->assertStringContainsString('<a href="../ch1.xhtml#s1">', $two);
        $this->assertStringContainsString('<a href="../ch1.xhtml?a=1&amp;b=2">', $two);
        $this->assertStringContainsString('url(../ch1.xhtml)', $two);
        $this->assertStringContainsString('<a href="http://example.com/ch1.xhtml">', $two);
        $this->assertStringContainsString('<a href="#top">', $two);
        $this->assertStringContainsString('url(../ch1.xhtml#f)', $content->getContent('EPUB/css/style.css'));

        $moved = $content->getContent('EPUB/ch1.xhtml');
        $this->assertStringContainsString('href="css/style.css"', $moved);
        $this->assertStringContainsString('<a href="text/ch2.xhtml#t">', $moved);
        $this->assertStringContainsString('src="img/a.png"', $moved);
        $this->assertStringContainsString('<a href="ch1.xhtml#s1">', $moved);
        $this->assertSame(2, substr_count($content->getContent('EPUB/nav.xhtml'), 'href="ch1.xhtml"'));
        $this->assertSame(['CONTENT_NOT_WELL_FORMED'], $this->codes($epubFile->validate()));
    }

    public function testMovingAStylesheetRewritesItsImportsAndUrlsAndItsReferrers(): void
    {
        $epubFile = $this->open($this->linkedBook());
        $content = $epubFile->getContentManager();

        $content->moveContent('EPUB/css/style.css', 'EPUB/css/sub/style.css');

        $css = $content->getContent('EPUB/css/sub/style.css');
        $this->assertStringContainsString('@import "../other.css";', $css);
        $this->assertStringContainsString('url("../../img/a.png")', $css);
        $this->assertStringContainsString('url(../../text/ch1.xhtml#f)', $css);
        $this->assertStringContainsString('href="../css/sub/style.css"', $content->getContent('EPUB/text/ch1.xhtml'));
        $this->assertStringContainsString('@import "../css/sub/style.css";', $content->getContent('EPUB/text/ch2.xhtml'));
    }

    public function testMovingAnImageRewritesAttributesStyleAttributesAndSvgLinks(): void
    {
        $epubFile = $this->open($this->linkedBook());
        $content = $epubFile->getContentManager();

        $content->moveContent('EPUB/img/a.png', 'EPUB/images/a.png');

        $this->assertStringContainsString('src="../images/a.png"', $content->getContent('EPUB/text/ch1.xhtml'));
        $two = $content->getContent('EPUB/text/ch2.xhtml');
        $this->assertStringContainsString("url('../images/a.png')", $two);
        $this->assertStringContainsString('xlink:href="../images/a.png"', $two);
        $this->assertStringContainsString('url("../images/a.png")', $content->getContent('EPUB/css/style.css'));
    }

    public function testReferencesCanBeLeftAlone(): void
    {
        $epubFile = $this->open($this->linkedBook());
        $content = $epubFile->getContentManager();
        $before = $content->getContent('EPUB/text/ch2.xhtml');

        $content->moveContent('EPUB/text/ch1.xhtml', 'EPUB/ch1.xhtml', false);

        $this->assertSame($before, $content->getContent('EPUB/text/ch2.xhtml'));
        $this->assertContains('CONTENT_REFERENCE_MISSING', $this->codes($epubFile->validate()));
    }

    public function testDocumentsWithoutChangesAndMalformedDocumentsAreNotRewritten(): void
    {
        $epubFile = $this->open($this->linkedBook());
        $content = $epubFile->getContentManager();
        $plain = $content->getContent('EPUB/text/plain.xhtml');
        $broken = $content->getContent('EPUB/text/broken.xhtml');

        $content->moveContent('EPUB/text/ch1.xhtml', 'EPUB/ch1.xhtml');

        $this->assertSame($plain, $content->getContent('EPUB/text/plain.xhtml'));
        $this->assertSame($broken, $content->getContent('EPUB/text/broken.xhtml'));
    }

    public function testAMoveReportsADocumentItCannotRewrite(): void
    {
        $epubFile = $this->open($this->linkedBook());
        $content = $epubFile->getContentManager();
        $file = (string) $epubFile->getTempDir() . '/EPUB/text/ch2.xhtml';
        chmod($file, 0444);

        try {
            if (is_writable($file)) {
                $this->markTestSkipped('Read-only files are writable here (e.g. running as root).');
            }

            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Failed to update the references in: EPUB/text/ch2.xhtml');

            $content->moveContent('EPUB/text/ch1.xhtml', 'EPUB/ch1.xhtml');
        } finally {
            chmod($file, 0644);
        }
    }

    public function testACaseOnlyRenameWorksOnAnyFilesystem(): void
    {
        $epubFile = $this->open($this->linkedBook());
        $content = $epubFile->getContentManager();

        $content->moveContent('EPUB/text/ch1.xhtml', 'EPUB/text/Ch1.xhtml');

        $this->assertContains('Ch1.xhtml', scandir((string) $epubFile->getTempDir() . '/EPUB/text'));
        $this->assertNotContains('ch1.xhtml', scandir((string) $epubFile->getTempDir() . '/EPUB/text'));
        $this->assertSame('EPUB/text/Ch1.xhtml', $epubFile->getManifest()->get('ch1')?->path);
        $this->assertStringContainsString('<a href="Ch1.xhtml#s1">', $content->getContent('EPUB/text/ch2.xhtml'));
        $this->assertSame(['CONTENT_NOT_WELL_FORMED'], $this->codes($epubFile->validate()));
    }

    public function testACaseOnlyRenameRefusesAnotherExistingFile(): void
    {
        $epubFile = $this->open($this->linkedBook());
        $directory = (string) $epubFile->getTempDir() . '/EPUB/text';
        file_put_contents($directory . '/CH1.xhtml', EpubBuilder::xhtml('Other', '<p>Other</p>'));
        $variants = array_filter(scandir($directory) ?: [], static fn (string $name): bool => strtolower($name) === 'ch1.xhtml');
        if (count($variants) !== 2) {
            $this->markTestSkipped('The filesystem is case-insensitive: ch1.xhtml and CH1.xhtml are one file.');
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('already exists');

        $epubFile->getContentManager()->moveContent('EPUB/text/ch1.xhtml', 'EPUB/text/CH1.xhtml');
    }

    public function testGenerateFromHeadingsBuildsNestedEntriesInReadingOrder(): void
    {
        $epubFile = EpubFile::create($this->tmpDir . '/new.epub', 'Headings');
        $one = $epubFile->addChapter('One', '<h1 id="a">One</h1><h2>Sub &amp;  more</h2><h2 id="x">Sub2</h2><h3>Deep</h3><h4>Too deep</h4><h1>   </h1>');
        $two = $epubFile->addChapter('Two', '<p id="toc-1">taken</p><h2>Starts deeper</h2><h1>Two</h1>');
        $aside = $epubFile->addChapter('Aside', '<h1>Auxiliary</h1>');
        $broken = $epubFile->addChapter('Broken', '<h1>Broken</h1>');
        $epubFile->getSpine()->setLinear($aside->id, false);
        file_put_contents((string) $epubFile->getTempDir() . '/' . $broken->path, '<html><body><h1>Broken');
        $toc = $epubFile->getTableOfContents();

        $entries = $toc->generateFromHeadings();

        $expected = [
            new TocEntry('One', $one->path, 'a', [
                new TocEntry('Sub & more', $one->path, 'toc-1'),
                new TocEntry('Sub2', $one->path, 'x', [new TocEntry('Deep', $one->path, 'toc-2')]),
                // A heading deeper than the one before it nests under it, across documents too.
                new TocEntry('Starts deeper', $two->path, 'toc-2'),
            ]),
            new TocEntry('Two', $two->path, 'toc-3'),
        ];
        $this->assertEquals($expected, $entries);
        $this->assertEquals($expected, $toc->getEntries());
        $this->assertStringContainsString('<h2 id="toc-1">Sub &amp;  more</h2>', $epubFile->getContentManager()->getContent($one->path));
        $this->assertSame(['CONTENT_NOT_WELL_FORMED'], $this->codes($epubFile->validate()));
    }

    public function testGenerateFromHeadingsHonoursTheDeepestLevelAndKeepsDocumentsWithIds(): void
    {
        $epubFile = EpubFile::create($this->tmpDir . '/new.epub', 'Headings');
        $item = $epubFile->addChapter('One', '<h1 id="a">One</h1><h2 id="b">Two</h2>');
        $before = $epubFile->getContentManager()->getContent($item->path);

        $entries = $epubFile->getTableOfContents()->generateFromHeadings(1);

        $this->assertEquals([new TocEntry('One', $item->path, 'a')], $entries);
        $this->assertSame($before, $epubFile->getContentManager()->getContent($item->path));
    }

    public function testGenerateFromHeadingsRefusesBadInput(): void
    {
        $epubFile = EpubFile::create($this->tmpDir . '/new.epub', 'Headings');
        $epubFile->addChapter('One', '<p>No headings</p>');
        $toc = $epubFile->getTableOfContents();

        $withoutSpine = new TableOfContents((string) $epubFile->getTempDir(), $epubFile->getManifest());
        $calls = [
            [static fn () => $toc->generateFromHeadings(0), 'must be 1 to 6'],
            [static fn () => $toc->generateFromHeadings(7), 'must be 1 to 6'],
            [static fn () => $toc->generateFromHeadings(), 'at least one entry'],
            [static fn () => $withoutSpine->generateFromHeadings(), 'spine is needed'],
        ];

        $messages = [];
        foreach ($calls as [$call, $message]) {
            try {
                $call();
                $this->fail("Expected an exception containing: {$message}");
            } catch (Exception $exception) {
                $messages[] = $exception->getMessage();
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertCount(4, $messages);
        $this->assertCount(1, $toc->getEntries());
    }

    public function testGenerateFromHeadingsNeedsANavigationDocumentOrNcx(): void
    {
        $epubFile = $this->open(EpubBuilder::minimal());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('no navigation document or NCX');

        $epubFile->getTableOfContents()->generateFromHeadings();
    }

    /**
     * Chapters that link to each other, a stylesheet, an image (also from a style attribute, a style element and
     * inline SVG), a document without references and one that is not well-formed.
     */
    private function linkedBook(): EpubBuilder
    {
        $xhtml = static fn (string $body, string $head = ''): string => '<?xml version="1.0" encoding="UTF-8"?><html xmlns="http://www.w3.org/1999/xhtml"'
            . ' xmlns:epub="http://www.idpf.org/2007/ops" xmlns:xlink="http://www.w3.org/1999/xlink"><head><title>T</title>' . $head . "</head><body id=\"top\">{$body}</body></html>";
        $items = '<item id="ch1" href="text/ch1.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="ch2" href="text/ch2.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="plain" href="text/plain.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="broken" href="text/broken.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="css" href="css/style.css" media-type="text/css"/>'
            . '<item id="img" href="img/a.png" media-type="image/png"/>';

        return EpubBuilder::epub3()
            ->withFile('EPUB/package.opf', str_replace(
                ['<item id="chapter" href="text/chapter.xhtml" media-type="application/xhtml+xml"/>', '<item id="style" href="css/style.css" media-type="text/css"/>', '<itemref idref="chapter"/>'],
                [$items, '', '<itemref idref="ch1"/><itemref idref="ch2"/>'],
                (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
            ))
            ->withoutFile('EPUB/text/chapter.xhtml')
            ->withFile('EPUB/nav.xhtml', $xhtml(
                '<nav epub:type="toc"><ol><li><a href="text/ch1.xhtml">One</a></li></ol></nav>'
                . '<nav epub:type="landmarks"><ol><li><a epub:type="bodymatter" href="text/ch1.xhtml">Start</a></li></ol></nav>'
            ))
            ->withFile('EPUB/text/ch1.xhtml', $xhtml(
                '<h1 id="s1">One</h1><a href="ch2.xhtml#t">two</a><img src="../img/a.png" alt=""/><a href="ch1.xhtml#s1">self</a>',
                '<link rel="stylesheet" type="text/css" href="../css/style.css"/>'
            ))
            ->withFile('EPUB/text/ch2.xhtml', $xhtml(
                '<a href="ch1.xhtml#s1">one</a><a href="ch1.xhtml?a=1&amp;b=2">q</a><a href="http://example.com/ch1.xhtml">r</a><a href="#top">t</a>'
                . "<p style=\"background: url('../img/a.png')\">x</p>"
                . '<svg xmlns="http://www.w3.org/2000/svg"><image xlink:href="../img/a.png" width="1" height="1"/></svg>',
                '<style>@import "../css/style.css"; .a { background: url(ch1.xhtml); }</style>'
            ))
            ->withFile('EPUB/text/plain.xhtml', "<?xml version='1.0'?><html xmlns='http://www.w3.org/1999/xhtml'><head><title>P</title></head><body><p>plain</p></body></html>")
            ->withFile('EPUB/text/broken.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body><a href="ch1.xhtml">broken')
            ->withFile('EPUB/css/style.css', "@import \"other.css\";\nbody { background: url(\"../img/a.png\"); }\n.x { background: url(../text/ch1.xhtml#f); }")
            ->withFile('EPUB/img/a.png', (string) base64_decode(EpubBuilder::PNG, true));
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/in-' . bin2hex(random_bytes(4)) . '.epub'));
    }

    /**
     * @param list<ValidationIssue> $issues
     *
     * @return list<string>
     */
    private function codes(array $issues): array
    {
        return array_map(static fn (ValidationIssue $issue): string => $issue->code, $issues);
    }
}
