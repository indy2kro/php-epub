<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use DOMDocument;
use DOMElement;
use Iterator;
use PhpEpub\ConversionException;
use PhpEpub\Converter;
use PhpEpub\Converters\HtmlAdapter;
use PhpEpub\Converters\MarkdownAdapter;
use PhpEpub\Converters\TextAdapter;
use PhpEpub\EpubFile;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExportAdaptersTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'export';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testLoaderReportsChapterPathsLinearFlagsAndTocTitles(): void
    {
        $directory = self::twoChapterBook()->writeTo($this->tmpDir . '/book');

        $document = (new \PhpEpub\Converters\EpubDocumentLoader())->load($directory);

        $this->assertSame(['EPUB/one.xhtml', 'EPUB/two.xhtml', 'EPUB/notes.xhtml'], $document->chapterPaths);
        $this->assertSame([true, true, false], $document->chapterLinear);
        $this->assertSame(['Part One', 'The Second Part', ''], $document->tocTitles);
    }

    public function testTextHasTitleChapterHeadingsAndBlankLineSeparatedParagraphs(): void
    {
        $directory = self::twoChapterBook()->writeTo($this->tmpDir . '/book');

        $text = (new TextAdapter())->toString($directory);

        $this->assertStringStartsWith("Two Chapters\n============\n\nby Ann Author", $text);
        // The TOC titles the chapters; the first line repeating the title is shown once.
        $this->assertStringContainsString("Part One\n========\n\nFirst paragraph.\n\nSecond paragraph.\n", $text);
        // A TOC title that differs from the chapter's own first line is shown above it.
        $this->assertStringContainsString("The Second Part\n===============\n\nPart Two\n\nIn part two.", $text);
        $this->assertLessThan(strpos($text, 'The Second Part'), strpos($text, 'Part One'));
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringNotContainsString("\u{200B}", $text);
        $this->assertStringNotContainsString('Notes text', $text);
    }

    public function testTextCanIncludeNonLinearItemsAndSkipHeadings(): void
    {
        $directory = self::twoChapterBook()->writeTo($this->tmpDir . '/book');

        $this->assertStringContainsString('Notes text', (new TextAdapter(includeNonLinear: true))->toString($directory));

        $plain = (new TextAdapter(headings: false))->toString($directory);
        $this->assertStringNotContainsString('Two Chapters', $plain);
        $this->assertStringNotContainsString('====', $plain);
        $this->assertStringContainsString("Part One\n\nFirst paragraph.", $plain);
    }

    public function testTextConvertWritesAFile(): void
    {
        $directory = self::twoChapterBook()->writeTo($this->tmpDir . '/book');
        $output = $this->tmpDir . '/book.txt';

        (new TextAdapter())->convert($directory, $output);

        $this->assertStringContainsString('In part two.', (string) file_get_contents($output));

        $this->expectException(ConversionException::class);
        (new TextAdapter())->convert($directory, $this->tmpDir . '/missing/dir/book.txt');
    }

    public function testExportersFitTheConverterFormatMap(): void
    {
        $directory = self::twoChapterBook()->writeTo($this->tmpDir . '/book');
        $converter = new Converter($directory, ['txt' => new TextAdapter(), 'html' => new HtmlAdapter(), 'md' => new MarkdownAdapter()]);

        foreach (['txt', 'html', 'md'] as $format) {
            $converter->convert($format, $this->tmpDir . '/out.' . $format);
            $this->assertStringContainsString('In part two.', (string) file_get_contents($this->tmpDir . '/out.' . $format));
        }
    }

    public function testHtmlIsOneDocumentWithScopedCssAndWorkingLinks(): void
    {
        $directory = self::twoChapterBook()->writeTo($this->tmpDir . '/book');

        $html = (new HtmlAdapter())->toString($directory);

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<title>Two Chapters</title>', $html);
        $this->assertStringContainsString('<nav class="epub-toc">', $html);
        $this->assertStringContainsString('<a href="#epub-c1">The Second Part</a>', $html);
        $this->assertMatchesRegularExpression('/\.epub-book \.note\s*\{\s*color:\s*red/', $html);
        $this->assertMatchesRegularExpression('/\.epub-book\s*\{\s*font-family:\s*serif/', $html);
        $this->assertDoesNotMatchRegularExpression('/^body\s*\{/m', $html);
        // Chapter 1 links to a heading of chapter 2, and to a missing target.
        $this->assertStringContainsString('<a href="#epub-c1-sec">Go</a>', $html);
        $this->assertMatchesRegularExpression('/<h2 id="sec"/', $html);
        $this->assertStringContainsString('id="epub-c1-sec"', $html);
        $this->assertStringContainsString('<a>Dangling</a>', $html);
        $this->assertSame(1, substr_count($html, '<style>'));
    }

    public function testHtmlLeavesOutNonLinearItemsOnRequest(): void
    {
        $directory = self::twoChapterBook()->writeTo($this->tmpDir . '/book');

        $this->assertStringContainsString('Notes text', (new HtmlAdapter())->toString($directory));
        $this->assertStringNotContainsString('Notes text', (new HtmlAdapter(includeNonLinear: false))->toString($directory));
    }

    public function testHtmlInlinesImagesWithinTheBudgetAndKeepsAltTextBeyondIt(): void
    {
        $directory = self::imageBook()->writeTo($this->tmpDir . '/book');

        $html = (new HtmlAdapter())->toString($directory);
        $this->assertStringContainsString('<img src="data:image/png;base64,' . EpubBuilder::PNG . '" alt="A dot">', $html);

        $tight = (new HtmlAdapter(maxInlinedBytes: 40))->toString($directory);
        $this->assertStringNotContainsString('<img', $tight);
        $this->assertStringContainsString('[A dot]', $tight);
    }

    public function testHtmlFromAHostileBookIsSafe(): void
    {
        $directory = self::hostileBook()->writeTo($this->tmpDir . '/book');

        $html = (new HtmlAdapter())->toString($directory);

        $this->assertNothingActive($html);
        $this->assertNoHostileText($html);
        $this->assertStringContainsString('href="http://example.com/ok"', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('[remote]', $html);
        $this->assertMatchesRegularExpression('/\.epub-book p\s*\{\s*color:\s*red/', $html);
        // Every url() that survived is an inlined image.
        preg_match_all('/url\(([^)]*)\)/', $html, $urls);
        foreach ($urls[1] as $url) {
            $this->assertStringStartsWith('"data:image/png;base64,', $url);
        }

        // The page it sits on is not restyled, and a policy forbids anything remote.
        $this->assertStringContainsString("default-src 'none'", $html);
        $this->assertMatchesRegularExpression('/\.epub-book\s*\{\s*background:/', $html);
    }

    public function testHtmlBookCssCannotEscapeItsContainer(): void
    {
        $css = "p{x:\"\n} body{background:red} q{y:\"}\n";
        $directory = self::cssBook($css)->writeTo($this->tmpDir . '/book');

        $html = (new HtmlAdapter())->toString($directory);

        // The sheet with a string that runs past its line is dropped as a whole.
        $this->assertStringNotContainsString('background:red', $html);

        $css = "body ~ div{color:red} :root ~ *{color:red} html + *{color:red} body > h1{color:blue} .a{position:fixed;inset:0;z-index:99999;color:green}\n"
            . "p{background:url(http://evil.example/x.png} .b{color:teal}\n";
        $directory = self::cssBook($css)->writeTo($this->tmpDir . '/book2');

        $html = (new HtmlAdapter())->toString($directory);

        $this->assertStringNotContainsString('~', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('position:fixed', str_replace(' ', '', $html));
        $this->assertStringNotContainsString('sticky', $html);
        $this->assertSame(2, substr_count($html, 'contain:layout paint'));
        $this->assertStringContainsString('overflow:hidden', $html);
    }

    public function testHtmlStyleAttributesCannotPositionAnElementOverThePage(): void
    {
        $css = ".a{color:green}\n";
        $directory = self::cssBook($css)->writeTo($this->tmpDir . '/book');

        $html = (new HtmlAdapter())->toString($directory);

        $this->assertStringContainsString('color:green', $html);
        $this->assertDoesNotMatchRegularExpression('/position:\s*(?:fixed|sticky)/i', $html);
    }
    public function testMarkdownConvertsStructure(): void
    {
        $directory = self::markdownBook()->writeTo($this->tmpDir . '/book');

        $export = (new MarkdownAdapter())->export($directory);

        $expected = <<<'MD'
# Rich Book

*Ann Author*

# Heading

A paragraph with *emphasis*, **strong**, `code` and a [link](http://example.com/a%20b).

> Quoted
>
> text

- one
- two
  - nested
1. first
2. second

```php
echo 1;
```

| Name | Value |
| --- | --- |
| a | 1 |
| b \| c | 2 |

![A dot](images/dot.png)
MD;
        // Lists are separate blocks; normalise the one the test book writes without a blank line.
        $markdown = str_replace("  - nested\n\n1. first", "  - nested\n1. first", $export->markdown);
        $this->assertStringContainsString($this->firstLines($expected, 9), $markdown);
        $this->assertStringContainsString("- one\n- two\n  - nested", $markdown);
        $this->assertStringContainsString("1. first\n2. second", $markdown);
        $this->assertStringContainsString("```php\necho 1;\n```", $markdown);
        $this->assertStringContainsString("| Name | Value |\n| --- | --- |\n| a | 1 |\n| b \\| c | 2 |", $markdown);
        $this->assertStringContainsString('![A dot](images/dot.png)', $markdown);
        $this->assertSame(['images/dot.png'], array_keys($export->images));
        $this->assertSame((string) base64_decode(EpubBuilder::PNG, true), $export->images['images/dot.png']);
    }

    public function testMarkdownWritesBookAndImagesToADirectory(): void
    {
        $directory = self::markdownBook()->writeTo($this->tmpDir . '/book');
        $target = $this->tmpDir . '/out/nested';

        (new MarkdownAdapter())->export($directory)->writeTo($target);

        $this->assertFileExists($target . '/book.md');
        $this->assertFileExists($target . '/images/dot.png');
        $this->assertStringContainsString('![A dot](images/dot.png)', (string) file_get_contents($target . '/book.md'));
    }

    public function testMarkdownConvertWritesTheFileWithImagesBesideIt(): void
    {
        $directory = self::markdownBook()->writeTo($this->tmpDir . '/book');

        (new MarkdownAdapter())->convert($directory, $this->tmpDir . '/book.md');

        $this->assertFileExists($this->tmpDir . '/book.md');
        $this->assertFileExists($this->tmpDir . '/images/dot.png');
    }

    public function testMarkdownWriteToRefusesPathsOutsideTheImagesFolder(): void
    {
        $this->expectException(ConversionException::class);

        (new \PhpEpub\Converters\MarkdownExport('x', ['../evil.txt' => 'x']))->writeTo($this->tmpDir . '/out');
    }

    public function testMarkdownLimitsImagesAndKeepsAltText(): void
    {
        $directory = self::markdownBook()->writeTo($this->tmpDir . '/book');

        $export = (new MarkdownAdapter(maxImageBytes: 10))->export($directory);

        $this->assertSame([], $export->images);
        $this->assertStringContainsString('\[A dot\]', $export->markdown);
        $this->assertStringNotContainsString('images/', $export->markdown);
    }

    public function testMarkdownFromAHostileBookHasNoHtmlOrActiveLinks(): void
    {
        $directory = self::hostileBook()->writeTo($this->tmpDir . '/book');

        $export = (new MarkdownAdapter())->export($directory);
        $markdown = $export->markdown;

        $this->assertStringContainsString('[ok](http://example.com/ok)', $markdown);
        $this->assertStringContainsString('![local](images/img.png)', $markdown);
        $this->assertSame(['images/img.png'], array_keys($export->images));
        foreach (['evil.example', 'alert', 'javascript', 'onerror', 'onclick', 'data:text', 'srcset', '@import'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $markdown);
        }

        // No raw markup: every "<" is escaped.
        $this->assertDoesNotMatchRegularExpression('/(?<!\\\\)</', $markdown);
        $this->assertStringContainsString('\<b\>not bold\</b\>', $markdown);
    }

    public function testTextFromAHostileBookIsPlain(): void
    {
        $directory = self::hostileBook()->writeTo($this->tmpDir . '/book');

        $text = (new TextAdapter())->toString($directory);

        $this->assertStringContainsString('Hello', $text);
        foreach (['evil.example', 'alert', 'onerror', '@import', 'color:red'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text);
        }
    }

    #[DataProvider('fixtureBooks')]
    public function testFixtureBooksExportInEveryFormat(string $fixture): void
    {
        $book = EpubFile::open($fixture);
        $title = $book->getMetadata()->getTitle();
        $directory = (string) $book->getTempDir();

        $text = (new TextAdapter())->toString($directory);
        $html = (new HtmlAdapter())->toString($directory);
        $export = (new MarkdownAdapter())->export($directory);

        $this->assertNotSame('', trim($text));
        $this->assertNotSame('', trim($export->markdown));
        $this->assertNothingActive($html);
        if ($title !== '') {
            $this->assertStringContainsString(htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE), $html);
        }

        foreach ($export->images as $path => $bytes) {
            $this->assertMatchesRegularExpression('#^images/[A-Za-z0-9][A-Za-z0-9._-]*$#', $path);
            $this->assertNotFalse(getimagesizefromstring($bytes) ?: strlen($bytes) > 0 && str_ends_with($path, '.svg'));
        }

        $book->cleanup();
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function fixtureBooks(): Iterator
    {
        foreach (glob(dirname(__DIR__) . '/fixtures/valid*.epub') ?: [] as $fixture) {
            yield basename($fixture) => [$fixture];
        }
    }

    /**
     * Whole-document checks: only allowed elements and attributes, no script hooks, no remote loads.
     */
    private function assertNothingActive(string $html): void
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($document->getElementsByTagName('*') as $element) {
            $tag = strtolower($element->localName ?? '');
            $this->assertNotContains($tag, ['script', 'iframe', 'frame', 'object', 'embed', 'form', 'input', 'button', 'video', 'audio', 'source', 'link', 'base', 'svg', 'math', 'textarea', 'select'], "<{$tag}> in the output");
            if ($tag === 'meta') {
                $this->assertContains($element->getAttribute('name') ?: $element->getAttribute('http-equiv') ?: 'charset', ['charset', 'viewport', 'author', 'Content-Security-Policy']);
            }

            $this->assertElementIsHarmless($element);
        }
    }

    private function assertNoHostileText(string $html): void
    {
        foreach (['alert', 'evil.example', 'javascript:', 'onerror', 'onclick', 'onload', 'srcset', '@import', 'data:text/html'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $html);
        }
    }

    private function assertElementIsHarmless(DOMElement $element): void
    {
        foreach ($element->attributes ?? [] as $attribute) {
            $name = strtolower($attribute->nodeName);
            $this->assertStringStartsNotWith('on', $name);
            if ($name === 'src') {
                $this->assertSame('img', strtolower($element->localName ?? ''));
                $this->assertMatchesRegularExpression('#^data:image/(?:png|jpeg|gif|webp|svg\+xml);base64,#', $attribute->value);
            }

            if ($name === 'href') {
                $this->assertMatchesRegularExpression('/^(?:#|https?:|mailto:)/i', $attribute->value);
            }
        }
    }

    private function firstLines(string $text, int $count): string
    {
        return implode("\n", array_slice(explode("\n", $text), 0, $count));
    }

    private static function chapter(string $title, string $body, string $head = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>' . $title . '</title>' . $head . '</head><body>' . $body . '</body></html>';
    }

    /**
     * @param array<string, string> $entries href => title
     */
    private static function nav(array $entries): string
    {
        $items = '';
        foreach ($entries as $href => $title) {
            $items .= '<li><a href="' . $href . '">' . $title . '</a></li>';
        }

        return '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>Nav</title></head><body><nav epub:type="toc"><ol>' . $items . '</ol></nav></body></html>';
    }

    /**
     * Two linear chapters (titled "Part One" / "Part Two" by the TOC) and a non-linear notes page.
     */
    private static function twoChapterBook(): EpubBuilder
    {
        $opf = '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:identifier id="uid">urn:uuid:00000000-0000-0000-0000-000000000000</dc:identifier><dc:title>Two Chapters</dc:title><dc:creator>Ann Author</dc:creator><dc:language>en</dc:language></metadata>'
            . '<manifest><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
            . '<item id="one" href="one.xhtml" media-type="application/xhtml+xml"/><item id="two" href="two.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="notes" href="notes.xhtml" media-type="application/xhtml+xml"/><item id="css" href="style.css" media-type="text/css"/></manifest>'
            . '<spine><itemref idref="one"/><itemref idref="two"/><itemref idref="notes" linear="no"/></spine></package>';

        return (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', self::nav(['one.xhtml' => 'Part One', 'two.xhtml' => 'The Second Part']))
            ->withFile('EPUB/style.css', "body { font-family: serif }\n.note { color: red }")
            ->withFile('EPUB/one.xhtml', self::chapter('Part One', '<h1>Part One</h1><p>First paragraph.</p><p>Second paragraph.</p><p><a href="two.xhtml#sec">Go</a> <a href="#missing">Dangling</a> <a href="notes.xhtml">Notes</a></p>', '<link rel="stylesheet" href="style.css"/>'))
            ->withFile('EPUB/two.xhtml', self::chapter('Part Two', '<h2 id="sec">Part Two</h2><p>In part two.</p>'))
            ->withFile('EPUB/notes.xhtml', self::chapter('Notes', '<p>Notes text</p>'));
    }

    private static function imageBook(): EpubBuilder
    {
        return EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', EpubBuilder::opf('<item id="dot" href="dot.png" media-type="image/png"/>'))
            ->withFile('EPUB/chapter.xhtml', self::chapter('Dot', '<p>Look: <img src="dot.png" alt="A dot"/></p>'))
            ->withFile('EPUB/dot.png', (string) base64_decode(EpubBuilder::PNG, true));
    }

    private static function markdownBook(): EpubBuilder
    {
        $body = '<h1>Heading</h1><p>A paragraph with <em>emphasis</em>, <b>strong</b>, <code>code</code> and a <a href="http://example.com/a b">link</a>.</p>'
            . '<blockquote><p>Quoted</p><p>text</p></blockquote><ul><li>one</li><li>two<ul><li>nested</li></ul></li></ul><ol><li>first</li><li>second</li></ol>'
            . '<pre><code class="language-php">echo 1;</code></pre>'
            . '<table><thead><tr><th>Name</th><th>Value</th></tr></thead><tbody><tr><td>a</td><td>1</td></tr><tr><td>b | c</td><td>2</td></tr></tbody></table>'
            . '<p><img src="dot.png" alt="A dot"/></p>';

        return EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', str_replace('<dc:title>Minimal</dc:title>', '<dc:title>Rich Book</dc:title><dc:creator>Ann Author</dc:creator>', EpubBuilder::opf('<item id="dot" href="dot.png" media-type="image/png"/>')))
            ->withFile('EPUB/chapter.xhtml', self::chapter('Heading', $body))
            ->withFile('EPUB/dot.png', (string) base64_decode(EpubBuilder::PNG, true));
    }

    private static function cssBook(string $css): EpubBuilder
    {
        return EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', EpubBuilder::opf('<item id="css" href="style.css" media-type="text/css"/>'))
            ->withFile('EPUB/chapter.xhtml', self::chapter('Css', '<h1>T</h1><p class="a" style="position:fixed;inset:0;z-index:99999;color:green">x</p><p style="position: STICKY !important">y</p>', '<link rel="stylesheet" href="style.css"/>'))
            ->withFile('EPUB/style.css', $css);
    }
    private static function hostileBook(): EpubBuilder
    {
        $head = '<link rel="stylesheet" href="http://evil.example/x.css"/><link rel="stylesheet" href="style.css"/>'
            . '<style>@import url(http://evil.example/i.css); body { background: url(http://evil.example/b.png) } p { color: red; background: url(img.png) }</style>'
            . '<script>alert(1)</script><meta http-equiv="refresh" content="0;url=http://evil.example"/><base href="http://evil.example/"/>';
        $body = '<h1 onclick="alert(2)">Hello</h1><script>alert(3)</script>'
            . '<p>Hello <a href="javascript:alert(4)">js</a> <a href="JaVa&#09;Script:alert(5)">js2</a> <a href="http://example.com/ok">ok</a> <a href="data:text/html,x">data</a> &lt;b&gt;not bold&lt;/b&gt;</p>'
            . '<img src="http://evil.example/a.png" alt="remote"/><img src="img.png" alt="local" onerror="alert(6)"/><img src="//evil.example/a.png" alt="proto"/>'
            . '<img srcset="http://evil.example/a.png 1x" src="img.png" alt="two"/>'
            . '<iframe src="http://evil.example"></iframe><object data="x"></object><embed src="x"/>'
            . '<form action="http://evil.example"><input name="q"/><button>Go</button></form>'
            . '<svg onload="alert(7)"><script>alert(8)</script><image xlink:href="img.png"/></svg>'
            . '<p style="background:url(http://evil.example/s.png);color:blue">styled</p><video src="v.mp4"></video>'
            . '<style>p{background:url(//evil.example/t.png)}</style>';

        return EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', EpubBuilder::opf(
                '<item id="img" href="img.png" media-type="image/png"/><item id="css" href="style.css" media-type="text/css"/>'
            ))
            ->withFile('EPUB/chapter.xhtml', self::chapter('Hostile', $body, $head))
            ->withFile('EPUB/img.png', (string) base64_decode(EpubBuilder::PNG, true))
            ->withFile('EPUB/style.css', 'body { background: #fff url(img.png) } @import "http://evil.example/e.css"; .x { background: url("http://evil.example/c.png") }');
    }
}
