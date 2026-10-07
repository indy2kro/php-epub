<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\HtmlText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextExtractionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'text';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    #[DataProvider('documents')]
    public function testExtractsThePlainText(string $html, string $expected): void
    {
        $this->assertSame($expected, HtmlText::extract($html));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'paragraphs and headings' => [
            EpubBuilder::xhtml('Title', "<h1>Chapter  One</h1>\n<p>First   paragraph,\nsplit over lines.</p><p>Second.</p>"),
            "Chapter One\nFirst paragraph, split over lines.\nSecond.",
        ];
        yield 'inline markup stays on its line' => [
            '<html><body><p>A <b>bold</b> and <i>italic</i> <a href="#x">link</a>.</p></body></html>',
            'A bold and italic link.',
        ];
        yield 'scripts, styles and the head are left out' => [
            '<html><head><title>Hidden</title><style>p { color: red }</style></head><body><script>var a = "<p>x</p>";</script><p>Seen</p><style>b {}</style></body></html>',
            'Seen',
        ];
        yield 'entities are decoded' => [
            '<html><body><p>Fish &amp; chips &lt;3 &eacute;t&#233; &nbsp;&nbsp;done&#160;now</p></body></html>',
            'Fish & chips <3 été done now',
        ];
        yield 'line breaks, lists and tables' => [
            '<html><body><p>one<br/>two<br>three</p><ul><li>a</li><li>b</li></ul><table><tr><td>1</td><td>2</td></tr><tr><th>3</th><td>4</td></tr></table></body></html>',
            "one\ntwo\nthree\na\nb\n1 2\n3 4",
        ];
        yield 'HTML that is not well-formed' => ['<p>Unclosed <b>bold<p>Next one', "Unclosed bold\nNext one"];
        yield 'text outside blocks and comments' => ['<html><body>Loose <!-- hidden --> text<div>Block</div>tail</body></html>', "Loose text\nBlock\ntail"];
        yield 'nothing to read' => ['', ''];
        yield 'an empty body' => ['<html><body>  </body></html>', ''];
        yield 'UTF-16' => [
            "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><html><body><p>Привет, мир</p></body></html>', 'UTF-16LE', 'UTF-8'),
            'Привет, мир',
        ];
        yield 'declared ISO-8859-1' => ["<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><html><body><p>caf\xE9</p></body></html>", 'café'];
    }

    public function testContentManagerReadsOneDocument(): void
    {
        $epubFile = $this->open($this->book());

        $this->assertSame("Chapter\nText.", $epubFile->getContentManager()->getText('EPUB/text/chapter.xhtml'));
    }

    #[DataProvider('unreadablePaths')]
    public function testContentManagerRefusesAMissingDocumentAndPathsOutsideTheBook(string $path): void
    {
        $contentManager = $this->open($this->book())->getContentManager();

        $this->expectException(Exception::class);
        $contentManager->getText($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePaths(): iterable
    {
        yield 'missing' => ['EPUB/text/missing.xhtml'];
        yield 'outside the book' => ['../outside.xhtml'];
    }

    public function testReadsTheWholeBookInReadingOrder(): void
    {
        $epubFile = $this->open($this->book());

        $this->assertSame(
            [
                'EPUB/text/second.xhtml' => 'Second',
                'EPUB/text/chapter.xhtml' => "Chapter\nText.",
                'EPUB/text/utf16.xhtml' => 'Привет',
            ],
            $epubFile->getText()
        );
        $this->assertSame(
            ['EPUB/text/second.xhtml', 'EPUB/text/chapter.xhtml', 'EPUB/text/notes.xhtml', 'EPUB/text/utf16.xhtml'],
            array_keys($epubFile->getText(false))
        );
        $this->assertSame('Notes', $epubFile->getText(false)['EPUB/text/notes.xhtml']);
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }

    private function book(): EpubBuilder
    {
        $opf = str_replace(
            ['<item id="style"', '<itemref idref="chapter"/>'],
            [
                '<item id="second" href="text/second.xhtml" media-type="application/xhtml+xml"/>'
                . '<item id="notes" href="text/notes.xhtml" media-type="application/xhtml+xml"/>'
                . '<item id="utf16" href="text/utf16.xhtml" media-type="application/xhtml+xml"/>'
                . '<item id="gone" href="text/gone.xhtml" media-type="application/xhtml+xml"/>'
                . '<item id="remote" href="https://example.com/remote.xhtml" media-type="application/xhtml+xml"/><item id="style"',
                '<itemref idref="second"/><itemref idref="chapter"/><itemref idref="notes" linear="no"/><itemref idref="utf16"/>'
                . '<itemref idref="gone"/><itemref idref="remote"/><itemref idref="style"/>',
            ],
            (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
        );

        return EpubBuilder::epub3()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/text/second.xhtml', EpubBuilder::xhtml('Second', '<p>Second</p>'))
            ->withFile('EPUB/text/notes.xhtml', EpubBuilder::xhtml('Notes', '<p>Notes</p>'))
            ->withFile('EPUB/text/utf16.xhtml', "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>T</title></head><body><p>Привет</p></body></html>', 'UTF-16LE', 'UTF-8'));
    }
}
