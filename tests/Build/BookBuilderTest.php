<?php

declare(strict_types=1);

namespace PhpEpub\Test\Build;

use DateTimeImmutable;
use Iterator;
use PhpEpub\Build\BookBuilder;
use PhpEpub\Build\BookOptions;
use PhpEpub\Build\BuildLimits;
use PhpEpub\Build\BuiltBook;
use PhpEpub\BuildException;
use PhpEpub\EpubFile;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookBuilderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'build';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testMarkdownBuildsAValidBookWithNavigationNcxAndCover(): void
    {
        $options = new BookOptions(
            title: 'Test Book',
            authors: ['Ann Author', 'Bob Writer'],
            language: 'fr',
            description: 'About it',
            publisher: 'Pub',
            date: '2026-10-09',
            coverImage: self::png(),
            css: 'p { margin: 0 }',
            splitLevel: 2
        );
        $markdown = "Front text.\n\n# One\n\nFirst chapter.\n\n## One.One\n\nSub.\n\n# Two\n\nSecond [link](#one) and [elsewhere](#oneone).\n";

        $built = (new BookBuilder($options))->fromMarkdown($markdown);

        $this->assertInstanceOf(BuiltBook::class, $built);
        $this->assertSame(4, $built->chapterCount);
        $this->assertSame([], $built->warnings);

        $book = $built->open();
        $this->assertSame([], array_map(strval(...), $book->validate()));

        $metadata = $book->getMetadata();
        $this->assertSame('3.0', $metadata->getVersion());
        $this->assertSame('Test Book', $metadata->getTitle());
        $this->assertSame(['Ann Author', 'Bob Writer'], $metadata->getAuthors());
        $this->assertSame('fr', $metadata->getLanguage());
        $this->assertSame('About it', $metadata->getDescription());
        $this->assertSame('Pub', $metadata->getPublisher());
        $this->assertSame('2026-10-09', $metadata->getDate());
        $this->assertMatchesRegularExpression('/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $metadata->getUniqueIdentifier());

        // Front matter (named after the book), then one chapter per h1 and h2.
        $titles = array_map(static fn ($entry): string => $entry->title, $book->getTableOfContents()->getEntries());
        $this->assertSame(['Test Book', 'One', 'One.One', 'Two'], $titles);
        $this->assertCount(5, $book->getSpine()->get());

        $cover = $book->getCoverImage();
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $cover);
        $this->assertSame('image/png', $cover->mediaType);

        // The EPUB 2 NCX is there and lists the same chapters, and the spine points at it.
        $opf = $book->getContentManager()->getContent('EPUB/package.opf');
        $this->assertStringContainsString('<spine toc="ncx"', $opf);
        $ncx = $book->getContentManager()->getContent('EPUB/toc.ncx');
        $this->assertStringContainsString('<text>One.One</text>', $ncx);
        $this->assertStringContainsString('dtb:uid', $ncx);

        // Links between chapters point at the chapter files.
        $last = $book->getContentManager()->getContent('EPUB/text/chapter-004.xhtml');
        $this->assertStringContainsString('href="chapter-002.xhtml#one"', $last);
        $this->assertStringContainsString('href="chapter-003.xhtml#oneone"', $last);
        $this->assertStringContainsString('p { margin: 0 }', $book->getContentManager()->getContent('EPUB/css/style.css'));
        $book->cleanup();
    }

    public function testMarkdownBlocksAndInlineMarkup(): void
    {
        $markdown = <<<'MD'
# Title

A *em* and **strong** and ~~gone~~ and `co<de` and a [link](http://example.com/x_y_z "T") and <http://auto.example>.

Setext
======

* a
* b
    1. deep

1. one
2. two

> quote
> more

```php
echo "<b>";
```

    indented

---

| h1 | h2 |
|----|:--:|
| a  | b \| c |

Hard break and \*literal\* & <b>raw</b>
MD;

        $built = (new BookBuilder())->fromMarkdown(str_replace('Hard break', "Hard  \nbreak", $markdown));
        $html = $this->chapterHtml($built, 1) . $this->chapterHtml($built, 2);

        foreach (
            [
            '<em>em</em>', '<strong>strong</strong>', '<del>gone</del>', '<code>co&lt;de</code>',
            '<a href="http://example.com/x_y_z" rel="noopener noreferrer" title="T">link</a>', '<a href="http://auto.example" rel="noopener noreferrer">http://auto.example</a>',
            '<h1 id="setext">Setext</h1>', '<ul>', '<li>a</li>', '<ol>', '<blockquote>', '<pre><code class="language-php">echo "&lt;b&gt;";</code></pre>',
            '<pre><code>indented</code></pre>', '<hr/>', '<th>h1</th>', '<td>b | c</td>', '<br/>', '*literal*', '&lt;b&gt;raw&lt;/b&gt;', '&amp;',
            ] as $expected
        ) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public function testImagesComeOnlyFromTheSuppliedMap(): void
    {
        $markdown = "# Pics\n\n![A](img/a.png) ![B](./other/../img/b.png) ![C](c.png) ![D](missing.png) ![E](http://evil.example/e.png) ![F](data:image/png;base64,AAAA) ![S](logo.svg)\n";
        $options = new BookOptions(images: [
            'img/a.png' => self::png(),
            'img/b.png' => self::png(),
            'sub/c.png' => self::png(),
            'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        ]);

        $built = (new BookBuilder($options))->fromMarkdown($markdown);

        $html = $this->chapterHtml($built, 1);
        $this->assertSame(3, substr_count($html, '<img'));
        $this->assertStringContainsString('[D]', $html);
        $this->assertStringContainsString('[E]', $html);
        $this->assertStringContainsString('[F]', $html);
        $this->assertStringContainsString('[S]', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $warnings = implode("\n", $built->warnings);
        $this->assertStringContainsString('Image not supplied: missing.png', $warnings);
        $this->assertStringContainsString('Image not supplied: http://evil.example/e.png', $warnings);
        $this->assertStringContainsString('Skipped image logo.svg', $warnings);

        $book = $built->open();
        $this->assertSame([], array_map(strval(...), $book->validate()));
        $paths = $book->getContentManager()->getContentPaths();
        $this->assertContains('EPUB/images/img-001.png', $paths);
        $this->assertContains('EPUB/images/img-003.png', $paths);
        $this->assertNotContains('EPUB/images/img-004.png', $paths);
        foreach ($paths as $path) {
            $this->assertStringEndsNotWith('.svg', $path);
        }

        $book->cleanup();
    }

    /**
     * @param \Closure(BookBuilder, string): BuiltBook $build
     */
    #[DataProvider('hostileInputs')]
    public function testHostileInputNeverReachesTheBook(\Closure $build, string $input): void
    {
        $built = $build(new BookBuilder(new BookOptions(images: ['a.png' => self::png()], css: '@import url(http://evil.example/x.css); p { background: url(http://evil.example/y.png) }')), $input);

        $book = $built->open();
        $this->assertSame([], array_map(strval(...), $book->validate()));
        $content = $book->getContentManager();
        foreach ($content->getContentPaths() as $path) {
            if (! preg_match('/\.(xhtml|css|opf|ncx)$/', $path)) {
                continue;
            }

            $text = $content->getContent($path);
            if (str_ends_with($path, '.css')) {
                $this->assertStringNotContainsString('evil.example', $text);
                $this->assertStringNotContainsString('@import', $text);
                $this->assertStringNotContainsString('url(', $text);
            } elseif (str_contains($path, '/text/')) {
                $this->assertSafeXhtml($text);
            }
        }

        $this->assertStringContainsString('Visible', $this->chapterHtml($built, 1));
        $book->cleanup();
    }

    /**
     * @return Iterator<string, array{\Closure(BookBuilder, string): BuiltBook, string}>
     */
    public static function hostileInputs(): Iterator
    {
        yield 'markdown' => [static fn (BookBuilder $builder, string $input): BuiltBook => $builder->fromMarkdown($input), "# Visible\n\n<script>alert(1)</script> <img src=x onerror=alert(2)> [js](javascript:alert(3)) [js2](JaVa\tScript:alert(4)) ![x](http://evil.example/a.png)\n\n<iframe src=\"http://evil.example\"></iframe>"];
        yield 'html' => [static fn (BookBuilder $builder, string $input): BuiltBook => $builder->fromHtml($input), '<h1 onclick="alert(1)">Visible</h1><script>alert(2)</script><a href="javascript:alert(3)">js</a><img src="http://evil.example/a.png" onerror="alert(4)" alt="r"/><img src="a.png" onload="alert(5)" style="x:y" srcset="http://evil.example/z 2x"/><iframe src="http://evil.example"></iframe><object data="x"></object><embed src="x"/><form action="http://evil.example"><input name="q"/></form><svg onload="alert(6)"><script>alert(7)</script></svg><style>p{background:url(http://evil.example/s.png)}</style><p style="color:red" onmouseover="alert(8)">p</p>'];
        yield 'text' => [static fn (BookBuilder $builder, string $input): BuiltBook => $builder->fromText($input), "Chapter One\n\nVisible <script>alert(1)</script> onerror=alert(2) javascript:alert(3)"];
    }

    public function testHtmlIsSplitAtHeadingsEvenInWrappersWithLinksAndUniqueIds(): void
    {
        $html = '<html><head><title>x</title></head><body><div><h1>One</h1><p id="dup">a <a href="#two-target">go</a> <a href="#nowhere">lost</a></p></div>'
            . '<section><h1>Two</h1><p id="dup">b</p><p id="two-target">t</p><p id="1bad">bad id</p></section><h2>Not a chapter</h2><p>tail</p></body></html>';

        $built = (new BookBuilder())->fromHtml($html);

        $this->assertSame(2, $built->chapterCount);
        $first = $this->chapterHtml($built, 1);
        $second = $this->chapterHtml($built, 2);
        $this->assertStringContainsString('<a href="chapter-002.xhtml#two-target">go</a>', $first);
        $this->assertStringContainsString('<a>lost</a>', $first);
        $this->assertStringContainsString('<p id="dup">a', $first);
        $this->assertStringNotContainsString('id="dup"', $second);
        $this->assertStringNotContainsString('id="1bad"', $second);
        $this->assertStringContainsString('<h2>Not a chapter</h2>', $second);
        $book = $built->open();
        $this->assertSame([], array_map(strval(...), $book->validate()));
        $book->cleanup();
    }

    public function testHtmlWithoutHeadingsIsOneChapterNamedAfterTheBook(): void
    {
        $built = (new BookBuilder(new BookOptions(title: 'Just Text')))->fromHtml('<p>Only a paragraph.</p>');

        $this->assertSame(1, $built->chapterCount);
        $book = $built->open();
        $this->assertSame('Just Text', $book->getTableOfContents()->getEntries()[0]->title);
        $book->cleanup();
    }

    public function testTextSplitsAtChapterLinesAndJoinsParagraphLines(): void
    {
        $text = "Some intro\nstill intro.\n\nChapter 1\n\nHello there,\nworld.\n\nCHAPTER Two\n\nSecond.\n";

        $built = (new BookBuilder(new BookOptions(title: 'Plain')))->fromText($text);

        $this->assertSame(3, $built->chapterCount);
        $book = $built->open();
        $titles = array_map(static fn ($entry): string => $entry->title, $book->getTableOfContents()->getEntries());
        $this->assertSame(['Plain', 'Chapter 1', 'CHAPTER Two'], $titles);
        $this->assertStringContainsString('<p>Hello there, world.</p>', $this->chapterHtml($built, 2));
        $this->assertSame([], array_map(strval(...), $book->validate()));
        $book->cleanup();
    }

    public function testTextUsesACustomChapterPattern(): void
    {
        $text = "PART I\n\nOne.\n\nPART II\n\nTwo.\n\nChapter 9\n\nStill part two.";

        $built = (new BookBuilder(new BookOptions(chapterPattern: '/^PART [IVX]+$/')))->fromText($text);

        $this->assertSame(2, $built->chapterCount);
    }

    public function testInvalidChapterPatternIsRefused(): void
    {
        $this->expectException(BuildException::class);

        (new BookBuilder(new BookOptions(chapterPattern: '/(unclosed')))->fromText('x');
    }

    public function testCatastrophicChapterPatternFailsCleanly(): void
    {
        $this->expectException(BuildException::class);

        (new BookBuilder(new BookOptions(chapterPattern: '/^(a+)+$/')))->fromText(str_repeat('a', 5000) . '!');
    }

    /**
     * @param \Closure(): BookOptions $options
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRefused(\Closure $options): void
    {
        $this->expectException(BuildException::class);

        (new BookBuilder($options()))->fromMarkdown('# x');
    }

    /**
     * @return Iterator<string, array{\Closure(): BookOptions}>
     */
    public static function invalidOptions(): Iterator
    {
        yield 'empty title' => [static fn (): BookOptions => new BookOptions(title: '  ')];
        yield 'control character in the title' => [static fn (): BookOptions => new BookOptions(title: "Bad\x01title")];
        yield 'invalid UTF-8 in an author' => [static fn (): BookOptions => new BookOptions(authors: ["Bad\xFFauthor"])];
        yield 'empty author' => [static fn (): BookOptions => new BookOptions(authors: [''])];
        yield 'bad language' => [static fn (): BookOptions => new BookOptions(language: 'english please')];
        yield 'empty identifier' => [static fn (): BookOptions => new BookOptions(identifier: ' ')];
        yield 'bad date' => [static fn (): BookOptions => new BookOptions(date: 'next tuesday-ish?')];
        yield 'impossible date' => [static fn (): BookOptions => new BookOptions(date: '2026-02-30')];
        yield 'split level 0' => [static fn (): BookOptions => new BookOptions(splitLevel: 0)];
        yield 'split level 7' => [static fn (): BookOptions => new BookOptions(splitLevel: 7)];
        yield 'bad direction' => [static fn (): BookOptions => new BookOptions(direction: 'up')];
        yield 'cover that is not an image' => [static fn (): BookOptions => new BookOptions(coverImage: 'not an image')];
        yield 'svg cover' => [static fn (): BookOptions => new BookOptions(coverImage: '<svg xmlns="http://www.w3.org/2000/svg"/>')];
    }

    public function testDatesAndIdentifier(): void
    {
        $date = (new BookBuilder(new BookOptions(date: new DateTimeImmutable('2020-03-04 10:00'), identifier: 'urn:isbn:9780000000002')))->fromMarkdown('# x')->open();
        $this->assertSame('2020-03-04', $date->getMetadata()->getDate());
        $this->assertSame('urn:isbn:9780000000002', $date->getMetadata()->getUniqueIdentifier());
        $date->cleanup();

        $partial = (new BookBuilder(new BookOptions(date: '1999-05')))->fromMarkdown('# x')->open();
        $this->assertSame('1999-05', $partial->getMetadata()->getDate());
        $partial->cleanup();

        $parsed = (new BookBuilder(new BookOptions(date: 'March 5, 2001')))->fromMarkdown('# x')->open();
        $this->assertSame('2001-03-05', $parsed->getMetadata()->getDate());
        $parsed->cleanup();
    }

    public function testMetadataIsEscaped(): void
    {
        $built = (new BookBuilder(new BookOptions(title: 'A & B <i>', authors: ['"Q" <x>'], description: '<b>d</b>')))->fromMarkdown('# x');

        $book = $built->open();
        $this->assertSame('A & B <i>', $book->getMetadata()->getTitle());
        $this->assertSame(['"Q" <x>'], $book->getMetadata()->getAuthors());
        $this->assertSame([], array_map(strval(...), $book->validate()));
        $book->cleanup();
    }

    public function testEmptyContentIsRefused(): void
    {
        $builders = [
            'fromMarkdown' => static fn (BookBuilder $builder): BuiltBook => $builder->fromMarkdown("  \n\n "),
            'fromText' => static fn (BookBuilder $builder): BuiltBook => $builder->fromText("  \n\n "),
            'fromHtml' => static fn (BookBuilder $builder): BuiltBook => $builder->fromHtml("  \n\n "),
        ];
        foreach ($builders as $method => $build) {
            try {
                $build(new BookBuilder());
                $this->fail("{$method} accepted empty content");
            } catch (BuildException $exception) {
                $this->assertStringContainsString('no content', $exception->getMessage());
            }
        }
    }

    public function testInvalidUtf8AndControlCharactersInContentAreCleaned(): void
    {
        $built = (new BookBuilder())->fromMarkdown("# Ti\xFFtle\x01\n\nBo\x08dy \xC3\x28 text");

        $book = $built->open();
        $this->assertSame([], array_map(strval(...), $book->validate()));
        $this->assertStringContainsString("Ti\u{FFFD}tle", $this->chapterHtml($built, 1));
        $book->cleanup();
    }

    public function testLimitsAreEnforced(): void
    {
        $chapters = str_repeat("# H\n\ntext\n\n", 5);
        try {
            (new BookBuilder(new BookOptions(limits: new BuildLimits(maxChapters: 4))))->fromMarkdown($chapters);
            $this->fail('Too many chapters');
        } catch (BuildException $exception) {
            $this->assertStringContainsString('Too many chapters', $exception->getMessage());
        }

        try {
            (new BookBuilder(new BookOptions(limits: new BuildLimits(maxTotalBytes: 20))))->fromMarkdown(str_repeat('x', 100));
            $this->fail('Too large');
        } catch (BuildException $exception) {
            $this->assertStringContainsString('too large', $exception->getMessage());
        }

        try {
            (new BookBuilder(new BookOptions(images: ['a.png' => self::png(), 'b.png' => self::png()], limits: new BuildLimits(maxImages: 1))))->fromMarkdown('# x');
            $this->fail('Too many images');
        } catch (BuildException $exception) {
            $this->assertStringContainsString('Too many images', $exception->getMessage());
        }
    }

    public function testOversizedImagesAreSkippedWithAWarning(): void
    {
        $built = (new BookBuilder(new BookOptions(
            images: ['small.png' => self::png(), 'wide.png' => self::png(40, 40)],
            limits: new BuildLimits(maxPixels: 100)
        )))->fromMarkdown('![a](small.png) ![b](wide.png)');

        $html = $this->chapterHtml($built, 1);
        $this->assertSame(1, substr_count($html, '<img'));
        $this->assertStringContainsString('Skipped image wide.png', implode("\n", $built->warnings));

        $byteLimited = (new BookBuilder(new BookOptions(images: ['small.png' => self::png()], limits: new BuildLimits(maxImageBytes: 10))))->fromMarkdown('![a](small.png)');
        $this->assertStringContainsString('larger than 10 bytes', implode("\n", $byteLimited->warnings));
    }

    public function testBuiltBookCanBeSavedAndReopened(): void
    {
        $built = (new BookBuilder(new BookOptions(title: 'Saved')))->fromMarkdown('# Hello');
        $path = $this->tmpDir . '/saved.epub';

        $built->save($path);

        $this->assertSame($built->toString(), file_get_contents($path));
        $this->assertStringStartsWith("PK\x03\x04", $built->toString());
        $reopened = EpubFile::open($path);
        $this->assertSame('Saved', $reopened->getMetadata()->getTitle());
        $reopened->cleanup();

        $this->expectException(BuildException::class);
        $built->save($this->tmpDir . '/missing/dir/saved.epub');
    }

    public function testImagesBuildAFixedLayoutBook(): void
    {
        $pages = [
            ['name' => 'p10.png', 'bytes' => self::png(30, 20)],
            ['name' => 'p2.png', 'bytes' => self::png(10, 40)],
            ['name' => 'notes.txt', 'bytes' => 'not an image'],
            ['name' => 'p1.jpg', 'bytes' => self::jpeg(16, 24)],
        ];

        $built = (new BookBuilder(new BookOptions(title: 'Comic', authors: ['Artist'], direction: 'rtl', language: 'ja')))->fromImages($pages);

        $this->assertSame(3, $built->chapterCount);
        $this->assertSame(['Skipped notes.txt: it is not a JPEG, PNG, GIF or WebP image'], $built->warnings);

        $book = $built->open();
        $this->assertSame([], array_map(strval(...), $book->validate()));
        $this->assertSame('pre-paginated', $book->getMetadata()->getRenditionLayout());
        $this->assertSame('rtl', $book->getSpine()->getPageProgressionDirection());
        $this->assertCount(3, $book->getSpine()->get());

        $content = $book->getContentManager();
        $this->assertStringContainsString('<meta name="viewport" content="width=30, height=20"/>', $content->getContent('EPUB/text/page-001.xhtml'));
        $this->assertStringContainsString('<meta name="viewport" content="width=10, height=40"/>', $content->getContent('EPUB/text/page-002.xhtml'));
        $this->assertStringContainsString('<meta name="viewport" content="width=16, height=24"/>', $content->getContent('EPUB/text/page-003.xhtml'));
        $this->assertStringContainsString('src="../images/page-003.jpg"', $content->getContent('EPUB/text/page-003.xhtml'));

        $cover = $book->getCoverImage();
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $cover);
        $this->assertSame('EPUB/images/page-001.png', $cover->path);

        $titles = array_map(static fn ($entry): string => $entry->title, $book->getTableOfContents()->getEntries());
        $this->assertSame(['Page 1', 'Page 2', 'Page 3'], $titles);
        $this->assertStringContainsString('<text>Page 3</text>', $content->getContent('EPUB/toc.ncx'));
        $book->cleanup();
    }

    public function testImagesCanBeSortedNaturally(): void
    {
        $pages = [
            ['name' => 'p10.png', 'bytes' => self::png(30, 20)],
            ['name' => 'p2.png', 'bytes' => self::png(10, 40)],
        ];

        $built = (new BookBuilder())->fromImages($pages, naturalSort: true);

        $book = $built->open();
        $this->assertStringContainsString('width=10, height=40', $book->getContentManager()->getContent('EPUB/text/page-001.xhtml'));
        $book->cleanup();
    }

    public function testImagesRefuseNothingUsableAndBadEntries(): void
    {
        foreach ([[], [['name' => 'a.txt', 'bytes' => 'x']], [['name' => 'svg.svg', 'bytes' => '<svg xmlns="http://www.w3.org/2000/svg"/>']]] as $pages) {
            try {
                (new BookBuilder())->fromImages($pages);
                $this->fail('Accepted unusable images');
            } catch (BuildException $exception) {
                $this->assertStringContainsString('no usable image', $exception->getMessage());
            }
        }

        $this->expectException(BuildException::class);
        /** @phpstan-ignore-next-line */
        (new BookBuilder())->fromImages([['name' => 'a.png']]);
    }

    public function testImageLimits(): void
    {
        $pages = [['name' => 'a.png', 'bytes' => self::png()], ['name' => 'b.png', 'bytes' => self::png()]];

        try {
            (new BookBuilder(new BookOptions(limits: new BuildLimits(maxChapters: 1))))->fromImages($pages);
            $this->fail('Too many pages');
        } catch (BuildException $exception) {
            $this->assertStringContainsString('Too many pages', $exception->getMessage());
        }

        $this->expectException(BuildException::class);
        (new BookBuilder(new BookOptions(limits: new BuildLimits(maxTotalBytes: 10))))->fromImages($pages);
    }

    /**
     * Only the elements and attributes a sanitised chapter can have (anything the hostile input said is text now).
     */
    private function assertSafeXhtml(string $xhtml): void
    {
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xhtml, LIBXML_NONET));

        $allowedTags = ['html', 'head', 'title', 'link', 'body', 'h1', 'h2', 'h3', 'p', 'a', 'img', 'em', 'strong', 'span', 'div', 'br', 'code', 'pre', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'thead', 'tbody', 'del', 'blockquote', 'hr', 'sub', 'sup', 'h4', 'h5', 'h6'];
        foreach ($document->getElementsByTagName('*') as $element) {
            $this->assertContains($element->localName, $allowedTags, "<{$element->localName}> in a chapter");
            foreach ($element->attributes ?? [] as $attribute) {
                $name = $attribute->nodeName;
                $this->assertStringStartsNotWith('on', $name);
                $this->assertNotSame('style', $name);
                $this->assertNotSame('srcset', $name);
                if ($name === 'href' && $element->localName === 'a') {
                    $this->assertMatchesRegularExpression('/^(?:#|https?:\/\/|mailto:|chapter-\d{3}\.xhtml#)/', $attribute->value);
                }

                if ($name === 'src') {
                    $this->assertMatchesRegularExpression('#^\.\./images/[A-Za-z0-9.-]+$#', $attribute->value);
                }
            }
        }
    }

    private function chapterHtml(BuiltBook $built, int $number): string
    {
        $book = $built->open();
        try {
            return $book->getContentManager()->getContent(sprintf('EPUB/text/chapter-%03d.xhtml', $number));
        } finally {
            $book->cleanup();
        }
    }

    private static function png(int $width = 1, int $height = 1): string
    {
        if ($width === 1 && $height === 1) {
            return (string) base64_decode(EpubBuilder::PNG, true);
        }

        return self::image($width, $height, imagepng(...));
    }

    private static function jpeg(int $width, int $height): string
    {
        return self::image($width, $height, imagejpeg(...));
    }

    /**
     * @param callable(\GdImage): mixed $encode
     */
    private static function image(int $width, int $height, callable $encode): string
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height)) ?: throw new \RuntimeException('GD cannot create an image');
        ob_start();
        $encode($image);

        return (string) ob_get_clean();
    }
}
