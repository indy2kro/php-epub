<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use PhpEpub\ConversionException;
use PhpEpub\Converters\EpubDocumentLoader;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Test\Support\UnreadableFile;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

final class EpubDocumentLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'loader';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testLoadsSpineDocumentsInReadingOrderWithMetadata(): void
    {
        $directory = self::twoChapterBook()->writeTo($this->tmpDir . '/book');

        $document = (new EpubDocumentLoader())->load($directory);

        $this->assertSame('Two Chapters', $document->title);
        $this->assertSame(['Ann Author', 'Bob Writer'], $document->authors);
        $this->assertCount(2, $document->chapters);
        // The spine lists chapter two first.
        $this->assertStringContainsString('Second file, first in the spine', $document->chapters[0]);
        $this->assertStringContainsString('First file, second in the spine', $document->chapters[1]);
        $this->assertStringNotContainsString('<body', $document->chapters[0]);
        $this->assertStringNotContainsString('<title>', $document->chapters[0]);
    }

    public function testSkipsSpineItemsThatAreNotXhtml(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', str_replace(
                '<itemref idref="chapter"/>',
                '<itemref idref="chapter"/><itemref idref="drawing"/>',
                EpubBuilder::opf('<item id="drawing" href="drawing.svg" media-type="image/svg+xml"/>')
            ))
            ->withFile('EPUB/drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')
            ->writeTo($this->tmpDir . '/book');

        $this->assertCount(1, (new EpubDocumentLoader())->load($directory)->chapters);
    }

    public function testImageSourcesOnlyPointInsideTheBook(): void
    {
        $body = '<img src="images/ok.png"/><img src="/etc/passwd"/><img src="https://example.com/t.png"/>'
            . '<img src="../../../outside.png"/><img src="file:///etc/hosts"/><img src="data:image/png;base64,AAAA"/>'
            . '<img src="images/missing.png"/><svg><image xlink:href="images/ok.png"/></svg>';
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/chapter.xhtml', "<html><body>{$body}</body></html>")
            ->withFile('EPUB/images/ok.png', 'png')
            ->writeTo($this->tmpDir . '/book');

        $html = (new EpubDocumentLoader())->load($directory)->chapters[0];

        $okPath = str_replace('\\', '/', (string) realpath($directory . '/EPUB/images/ok.png'));
        $this->assertSame(2, substr_count($html, $okPath));
        $this->assertStringContainsString('src="data:image/png;base64,AAAA"', $html);
        $this->assertStringNotContainsString('passwd', $html);
        $this->assertStringNotContainsString('example.com', $html);
        $this->assertStringNotContainsString('outside.png', $html);
        $this->assertStringNotContainsString('hosts', $html);
        $this->assertStringNotContainsString('missing.png', $html);
    }

    public function testEveryResourceReferenceIsConfinedToTheBook(): void
    {
        // A real file next to the book: renderers could read it if any reference slipped through.
        file_put_contents($this->tmpDir . '/secret.png', 'png');
        $outside = str_replace('\\', '/', (string) realpath($this->tmpDir . '/secret.png'));

        $body = "<img src={$outside} /><img src=images/ok.png /><img src='{$outside}'/>"
            . "<img srcset=\"{$outside} 2x\"/><svg><image href=\"{$outside}\"/></svg>"
            . "<object data=\"{$outside}\"></object><embed src=\"{$outside}\"/><video poster=\"{$outside}\"></video>"
            . "<p style=\"background: url({$outside})\">Styled</p><style>p { background: url({$outside}); }</style>"
            . "<link rel=\"stylesheet\" href=\"{$outside}\"/><a href=\"chapter.xhtml#top\">Link</a>";
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/chapter.xhtml', "<html><body>{$body}</body></html>")
            ->withFile('EPUB/images/ok.png', 'png')
            ->writeTo($this->tmpDir . '/book');

        $html = (new EpubDocumentLoader())->load($directory)->chapters[0];

        $this->assertStringNotContainsString('secret.png', $html);
        $okPath = str_replace('\\', '/', (string) realpath($directory . '/EPUB/images/ok.png'));
        $this->assertStringContainsString($okPath, $html);
        $this->assertStringContainsString('Styled', $html);
        $this->assertStringContainsString('href="chapter.xhtml#top"', $html);
    }

    public function testTextAndPathsSurviveParsing(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile(
                'EPUB/chapter.xhtml',
                '<?xml version="1.0" encoding="utf-8"?><html xmlns="http://www.w3.org/1999/xhtml"><body>'
                . '<p>Café — naïve &amp; ñ&nbsp;end</p><img src="images/a%26b.png"/></body></html>'
            )
            ->withFile('EPUB/images/a&b.png', 'png')
            ->writeTo($this->tmpDir . '/book');

        $html = (new EpubDocumentLoader())->load($directory)->chapters[0];

        $this->assertStringContainsString('Café — naïve &amp; ñ', $html);
        $imagePath = str_replace('\\', '/', (string) realpath($directory . '/EPUB/images/a&b.png'));
        $this->assertStringContainsString('src="' . htmlspecialchars($imagePath) . '"', $html);
    }

    public function testBookStylesheetsAreCollectedWithUrlsConfinedToTheBook(): void
    {
        file_put_contents($this->tmpDir . '/secret.png', 'png');
        file_put_contents($this->tmpDir . '/secret.css', '.secret { color: red; }');
        $css = 'p { color: red; } .ok { background: url("../images/ok.png"); } .bad { background: url(/etc/passwd); }'
            . ' @import url(other.css); .esc { background: u\72l(../images/ok.png); } q::before { content: "\201C"; }'
            . ' .set { background-image: image-set("../images/ok.png" 1x); } .out { background: url(../../../secret.png); }';
        $head = '<link rel="stylesheet" type="text/css" href="css/style.css"/>'
            . '<link rel="alternate stylesheet" href="css/alt.css"/><link rel="stylesheet" href="../../secret.css"/>'
            . '<link rel="stylesheet" href="css/missing.css"/><style>h1 { color: blue; }</style>';
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/chapter.xhtml', "<html><head>{$head}</head><body><p>Text</p></body></html>")
            ->withFile('EPUB/css/style.css', $css)
            ->withFile('EPUB/css/alt.css', '.alt { color: green; }')
            ->withFile('EPUB/images/ok.png', 'png')
            ->writeTo($this->tmpDir . '/book');

        $styles = implode("\n", (new EpubDocumentLoader())->load($directory)->styles);

        $okPath = str_replace('\\', '/', (string) realpath($directory . '/EPUB/images/ok.png'));
        $this->assertStringContainsString('p { color: red; }', $styles);
        $this->assertStringContainsString('h1 { color: blue; }', $styles);
        $this->assertSame(2, substr_count($styles, $okPath));
        $this->assertStringContainsString("content: \"\u{201C}\"", $styles);
        $this->assertStringNotContainsString('passwd', $styles);
        $this->assertStringNotContainsString('secret', $styles);
        $this->assertStringNotContainsString('@import', $styles);
        $this->assertStringNotContainsString('image-set', $styles);
        $this->assertStringNotContainsString('.alt', $styles);
    }

    public function testAStylesheetSharedByChaptersIsIncludedOnce(): void
    {
        $link = '<link rel="stylesheet" href="style.css"/>';
        $directory = self::twoChapterBook()
            ->withFile('EPUB/chapter.xhtml', "<html><head>{$link}</head><body><p>One</p></body></html>")
            ->withFile('EPUB/text/two.xhtml', '<html><head><link rel="stylesheet" href="../style.css"/></head><body><p>Two</p></body></html>')
            ->withFile('EPUB/style.css', 'p { margin: 0; }')
            ->writeTo($this->tmpDir . '/book');

        $this->assertSame(['p { margin: 0; }'], (new EpubDocumentLoader())->load($directory)->styles);
    }

    public function testInlineStylesAreKeptWithConfinedUrls(): void
    {
        file_put_contents($this->tmpDir . '/secret.png', 'png');
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/chapter.xhtml', '<html><body><p style="color: red; background: url(images/ok.png)">A</p>'
                . '<p style="background: url(../../secret.png)">B</p></body></html>')
            ->withFile('EPUB/images/ok.png', 'png')
            ->writeTo($this->tmpDir . '/book');

        $html = (new EpubDocumentLoader())->load($directory)->chapters[0];

        $okPath = str_replace('\\', '/', (string) realpath($directory . '/EPUB/images/ok.png'));
        $this->assertStringContainsString('color: red', $html);
        $this->assertStringContainsString($okPath, $html);
        $this->assertStringNotContainsString('secret', $html);
    }

    public function testEmptyChapterBecomesAnEmptyString(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/chapter.xhtml', '')
            ->writeTo($this->tmpDir . '/book');

        $this->assertSame([''], (new EpubDocumentLoader())->load($directory)->chapters);
    }

    public function testScriptsAreRemoved(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/chapter.xhtml', '<html><body><p>Text</p><script type="text/javascript">alert(1)</script></body></html>')
            ->writeTo($this->tmpDir . '/book');

        $html = (new EpubDocumentLoader())->load($directory)->chapters[0];

        $this->assertStringContainsString('<p>Text</p>', $html);
        $this->assertStringNotContainsString('alert', $html);
    }

    public function testLegacyContentXhtmlDirectoryIsStillSupported(): void
    {
        mkdir($this->tmpDir . '/legacy');
        file_put_contents($this->tmpDir . '/legacy/content.xhtml', '<html><body>Legacy</body></html>');

        $document = (new EpubDocumentLoader())->load($this->tmpDir . '/legacy');

        $this->assertSame(['Legacy'], $document->chapters);
        $this->assertSame('', $document->title);
    }

    public function testMissingSpineDocumentThrows(): void
    {
        $directory = EpubBuilder::minimal()->writeTo($this->tmpDir . '/book');
        unlink($directory . '/EPUB/chapter.xhtml');

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Failed to read content from: EPUB/chapter.xhtml');

        (new EpubDocumentLoader())->load($directory);
    }

    public function testUnreadableChapterThrowsInsteadOfRenderingNothing(): void
    {
        $directory = EpubBuilder::minimal()->writeTo($this->tmpDir . '/book');
        $chapter = UnreadableFile::make($directory . '/EPUB/chapter.xhtml');

        try {
            if (! $chapter->isUnreadable()) {
                $this->markTestSkipped('Unreadable files are readable here (e.g. running as root).');
            }

            $this->expectException(ConversionException::class);
            $this->expectExceptionMessage('Failed to read content from: EPUB/chapter.xhtml');

            (new EpubDocumentLoader())->load($directory);
        } finally {
            $chapter->restore();
        }
    }

    public function testUnreadableStylesheetIsLeftOut(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/chapter.xhtml', '<html><head><link rel="stylesheet" href="style.css"/></head><body><p>Text</p></body></html>')
            ->withFile('EPUB/style.css', 'p { color: red; }')
            ->writeTo($this->tmpDir . '/book');
        $stylesheet = UnreadableFile::make($directory . '/EPUB/style.css');

        try {
            if (! $stylesheet->isUnreadable()) {
                $this->markTestSkipped('Unreadable files are readable here (e.g. running as root).');
            }

            $this->assertSame([], (new EpubDocumentLoader())->load($directory)->styles);
        } finally {
            $stylesheet->restore();
        }
    }

    public function testMissingDirectoryThrows(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('EPUB directory does not exist');

        (new EpubDocumentLoader())->load($this->tmpDir . '/nope');
    }

    public function testDirectoryWithoutBookThrows(): void
    {
        mkdir($this->tmpDir . '/empty');

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('No EPUB package found');

        (new EpubDocumentLoader())->load($this->tmpDir . '/empty');
    }

    public static function twoChapterBook(): EpubBuilder
    {
        $opf = str_replace(
            '<itemref idref="chapter"/>',
            '<itemref idref="two"/><itemref idref="chapter"/>',
            EpubBuilder::opf(
                '<item id="two" href="text/two.xhtml" media-type="application/xhtml+xml"/>',
                '<dc:creator>Ann Author</dc:creator><dc:creator>Bob Writer</dc:creator>'
            )
        );

        return EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', str_replace('<dc:title>Minimal</dc:title>', '<dc:title>Two Chapters</dc:title>', $opf))
            ->withFile('EPUB/chapter.xhtml', '<html><head><title>One</title></head><body><p>First file, second in the spine</p></body></html>')
            ->withFile('EPUB/text/two.xhtml', '<html><head><title>Two</title></head><body class="c"><p>Second file, first in the spine</p></body></html>');
    }
}
