<?php

declare(strict_types=1);

namespace PhpEpub\Test\Util;

use Iterator;
use PhpEpub\Util\HtmlSanitizer;
use PhpEpub\Util\HtmlToMarkdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlToMarkdownTest extends TestCase
{
    #[DataProvider('conversions')]
    public function testConvertsMarkup(string $html, string $expected): void
    {
        $this->assertSame($expected, self::convert($html));
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function conversions(): Iterator
    {
        yield 'markers at the start of a paragraph are escaped' => ['<p>1. not a list</p><p>- not an item</p><p># not a heading</p><p>---</p>', "1\\. not a list\n\n\\- not an item\n\n\\# not a heading\n\n\\---"];
        yield 'special characters are escaped' => ['<p>a*b_c `d` [e] &amp; <x></p>', 'a\\*b\\_c \\`d\\` \\[e\\] \\&'];
        yield 'emphasis keeps spaces outside the delimiters' => ['<p>a <b> bold </b> c <i></i>d</p>', 'a  **bold**  c d'];
        yield 'hard break' => ['<p>one<br>two</p>', "one  \ntwo"];
        yield 'code span with backticks' => ['<p><code>a`b</code></p>', '``a`b``'];
        yield 'code block fence grows' => ["<pre>x\n```\ny</pre>", "````\nx\n```\ny\n````"];
        yield 'ordered list start' => ['<ol start="3"><li>a</li><li>b</li></ol>', "3. a\n4. b"];
        yield 'list item with two paragraphs' => ['<ul><li><p>a</p><p>b</p></li></ul>', "- a\n\n  b"];
        yield 'internal and unsafe links lose their link' => ['<p><a href="#x">in</a> <a href="javascript:x">js</a> <a href="http://e.com/a(b)">out</a></p>', 'in js [out](http://e.com/a%28b%29)'];
        yield 'table with spans is plain text' => ['<table><tr><td colspan="2">a</td></tr><tr><td>b</td><td>c</td></tr></table>', "a  \nb | c"];
        yield 'ragged table is plain text' => ['<table><tr><td>a</td></tr><tr><td>b</td><td>c</td></tr></table>', "a  \nb | c"];
        yield 'table with a list is plain text' => ['<table><tr><td><ul><li>x</li></ul></td><td>c</td></tr></table>', '\- x | c'];
        yield 'heading is one line' => ["<h2>A\nB #</h2>", '## A B \\#'];
        yield 'blockquote with a list' => ['<blockquote><ul><li>a</li></ul></blockquote>', '> - a'];
        yield 'definition list' => ['<dl><dt>Term</dt><dd>Meaning</dd></dl>', "**Term**\n\nMeaning"];
        yield 'nested inline wrappers' => ['<div><span>one <a href="http://e.com">two</a></span><div>three</div></div>', "one [two](http://e.com)\n\nthree"];
    }

    public function testDeeplyNestedMarkupIsCapped(): void
    {
        // The converter reads past its depth cap as plain text; the sanitiser removes anything deeper than 100.
        $this->assertSame('deep', self::convert(str_repeat('<div>', 60) . 'deep' . str_repeat('</div>', 60)));
        $this->assertSame('', self::convert(str_repeat('<div>', 500) . 'deep' . str_repeat('</div>', 500)));
    }

    private static function convert(string $html): string
    {
        $body = HtmlSanitizer::parseBody($html);
        (new HtmlSanitizer(static fn (string $src): string => $src))->sanitize($body);

        return (new HtmlToMarkdown())->convert($body);
    }
}
