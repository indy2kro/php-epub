<?php

declare(strict_types=1);

namespace PhpEpub\Test\Build;

use PhpEpub\Build\BookBuilder;
use PhpEpub\Build\BookOptions;
use PhpEpub\Build\BuildLimits;
use PhpEpub\Build\BuiltBook;
use PhpEpub\BuildException;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of BookBuilder and its Markdown parser.
 */
final class BuildCoverageTest extends TestCase
{
    private static function chapter(BuiltBook $built, int $number): string
    {
        $book = $built->open();
        try {
            return $book->getContentManager()->getContent(sprintf('EPUB/text/chapter-%03d.xhtml', $number));
        } finally {
            $book->cleanup();
        }
    }

    private static function html(string $markdown): string
    {
        return self::chapter((new BookBuilder())->fromMarkdown($markdown), 1);
    }

    public function testTooManyComicImages(): void
    {
        $png = (string) base64_decode(EpubBuilder::PNG, true);

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('Too many images');

        (new BookBuilder(new BookOptions(limits: new BuildLimits(maxImages: 1))))->fromImages([['name' => 'a.png', 'bytes' => $png], ['name' => 'b.png', 'bytes' => $png]]);
    }

    public function testEmptyImageIsSkippedAndAnAnchorOnlyImageReferenceIsNotSupplied(): void
    {
        $built = (new BookBuilder(new BookOptions(images: ['empty.png' => ''])))->fromMarkdown("# T\n\n![a](#) ![b](empty.png)");

        $this->assertContains('Skipped image empty.png: it is empty', $built->warnings);
        $this->assertContains('Image not supplied: #', $built->warnings);
    }

    public function testWhitespaceBeforeTheFirstHeadingIsNotAChapter(): void
    {
        $built = (new BookBuilder())->fromHtml(" \n\u{00A0}<h1>One</h1><p>a</p><h1>Two</h1><p>b</p>");

        $this->assertSame(2, $built->chapterCount);
    }

    public function testWrappersWithoutHeadingsStayInTheirChapter(): void
    {
        $built = (new BookBuilder())->fromHtml('<h1>One</h1><div><p>boxed</p></div>');

        $this->assertSame(1, $built->chapterCount);
        $this->assertStringContainsString('boxed', self::chapter($built, 1));
    }

    public function testLinksWithinAChapterKeepTheirFragment(): void
    {
        $built = (new BookBuilder())->fromHtml('<h1>One</h1><p id="x">t <a href="#x">same chapter</a></p>');

        $this->assertStringContainsString('<a href="#x">same chapter</a>', self::chapter($built, 1));
    }

    public function testContentThatIsNotWellFormedAfterAllIsReported(): void
    {
        $parser = new class () extends XmlParser {
            public function parseString(string $content, string $source = 'string'): \SimpleXMLElement
            {
                throw new XmlException('boom');
            }
        };

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('Failed to produce valid XHTML for chapter-001: boom');

        (new BookBuilder(new BookOptions(), $parser))->fromMarkdown('# x');
    }

    public function testDeeplyNestedBlockQuotesAreCappedWithoutLosingText(): void
    {
        $this->assertStringContainsString('deep', self::html(str_repeat('> ', 30) . 'deep'));
    }

    public function testBlockQuoteLazyContinuation(): void
    {
        $this->assertStringContainsString("<blockquote>\n<p>a\nlazy</p>\n</blockquote>", self::html("> a\nlazy"));
    }

    public function testListItemsWithCodeBlankLinesAndLazyLines(): void
    {
        // Five or more spaces after the marker: the item starts with indented code.
        $this->assertStringContainsString('<li>', self::html("-      code\n"));
        $this->assertStringContainsString('code', self::html("-      code\n"));
        // A blank line between blocks of an item, and between items, makes a loose list.
        $this->assertStringContainsString('<li><p>a</p>', self::html("- a\n\n  b\n"));
        $this->assertStringContainsString('<li><p>a</p>', self::html("- a\n\n- b\n"));
        // A line that is not indented continues the item's paragraph.
        $this->assertStringContainsString("<li>a\nlazy</li>", self::html("- a\nlazy\n"));
    }

    public function testHeadingWithAnExplicitId(): void
    {
        $this->assertStringContainsString('<h1 id="custom">Title</h1>', self::html('# Title {#custom}'));
    }
}
