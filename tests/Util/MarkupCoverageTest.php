<?php

declare(strict_types=1);

namespace PhpEpub\Test\Util;

use PhpEpub\Util\CssSanitizer;
use PhpEpub\Util\CssScope;
use PhpEpub\Util\CssTokenizer;
use PhpEpub\Util\HtmlSanitizer;
use PhpEpub\Util\HtmlToMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the markup helpers (HTML to Markdown, the sanitiser, the CSS tokenizer and scoper).
 */
final class MarkupCoverageTest extends TestCase
{
    private static function markdown(string $html, bool $sanitize = false): string
    {
        $body = HtmlSanitizer::parseBody($html);
        $sanitize && (new HtmlSanitizer(static fn (string $src): string => $src))->sanitize($body);

        return (new HtmlToMarkdown())->convert($body);
    }

    public function testMarkdownOfCaptionsEmptyTablesAndNestedTables(): void
    {
        $this->assertSame("x\n\n*Cap*", self::markdown('<figure><p>x</p><figcaption>Cap</figcaption></figure>'));
        $this->assertSame('', self::markdown('<figure><figcaption> </figcaption></figure>'));
        $this->assertSame('*Only*', self::markdown('<table><caption>Only</caption></table>'));
        $this->assertSame('', self::markdown('<table></table>'));
        // A table inside a table cannot be a GFM table: its text is kept (as plain text).
        $this->assertStringContainsString('a', self::markdown('<table><tr><td><table><tr><td>a</td></tr></table></td></tr></table>'));
    }

    public function testMarkdownOfInlineOddities(): void
    {
        $this->assertSame('"hi" a b', self::markdown('<p><q>hi</q> a<wbr> b<code> </code></p>'));
        $this->assertSame('漢 (kan)', self::markdown('<p><ruby>漢<rp>(</rp><rt>kan</rt><rp>)</rp></ruby></p>'));
        // An image without a source (the sanitiser never leaves one) is left out.
        $this->assertSame('', self::markdown('<p><img alt="a"></p>'));
        // Inline nesting deeper than the converter's cap is read as plain text.
        $this->assertStringContainsString('deep', self::markdown('<p>' . str_repeat('<b>', 60) . 'deep' . str_repeat('</b>', 60) . '</p>'));
    }

    public function testSanitizerLinksDirectionLanguageAndDroppedImages(): void
    {
        $this->assertFalse(HtmlSanitizer::isSafeLink("  \t "));
        $this->assertTrue(HtmlSanitizer::isSafeLink(' #top'));

        $body = HtmlSanitizer::parseBody('<p dir="RTL" xml:lang="fr">a</p><p dir="sideways" lang="de" xml:lang="fr">b</p><p>c <img src="missing.png"> d</p>');
        (new HtmlSanitizer(static fn (string $src): ?string => null))->sanitize($body);

        $html = (string) $body->ownerDocument?->saveHTML($body);
        $this->assertStringContainsString('<p dir="rtl" lang="fr">a</p>', $html);
        $this->assertStringContainsString('<p lang="de">b</p>', $html);
        // No alt text: nothing is left of the image.
        $this->assertStringContainsString('<p>c  d</p>', $html);
    }

    public function testSanitizerReplacesUnknownWrappersByTheirChildren(): void
    {
        $body = HtmlSanitizer::parseBody('<font color="red">a <b>b</b></font>');
        (new HtmlSanitizer(static fn (string $src): ?string => null))->sanitize($body);

        $this->assertSame('<body>a <b>b</b></body>', $body->ownerDocument?->saveHTML($body));
    }

    public function testMarkdownOfStrikethroughAndInlineWrappersAroundBlocks(): void
    {
        $this->assertSame('~~a~~~~b~~', self::markdown('<p><del>a</del><s>b</s></p>'));
        $this->assertSame('x', self::markdown('<a href="http://e.com"><div>x</div></a>'));
    }

    public function testTokenizerComments(): void
    {
        $this->assertSame([['other', 'a'], ['ws', ' '], ['other', 'b']], CssTokenizer::tokenize('a/* c */b'));
        $this->assertSame([['other', 'a'], ['ws', ' ']], CssTokenizer::tokenize('a/* never closed'));
    }

    public function testTokenizerStringsEscapesAndUrls(): void
    {
        $this->assertSame([['str', '"a\\"b"']], CssTokenizer::tokenize('"a\\"b"'));
        $this->assertNull(CssTokenizer::tokenize('"never closed'));
        $this->assertNull(CssTokenizer::tokenize('p{background:url(a"b)}'));
        $this->assertNull(CssTokenizer::tokenize('p{background:url(never'));
        $this->assertSame([['url', 'url( a.png )']], CssTokenizer::tokenize('url( a.png )'));
    }

    public function testScopeDropsNestedBlocksThatAreNotBalancedAndKeepsBracketedBlocks(): void
    {
        $this->assertSame('', CssScope::scope('@media print{ ) }', '.b'));
        $this->assertSame(".b a:is({ }){color:red}\n", CssScope::scope('a:is({ }) {color:red}', '.b'));
    }

    public function testRenamingHandlesEmptyBlocksOtherPropertiesAndStrings(): void
    {
        $css = '@font-face{font-family:A} @keyframes spin{from{top:0}} p{ } q{animation:none; font:12px B} r{animation-name:"spin"}';

        $scoped = CssScope::scope($css, '.b');

        $this->assertStringContainsString('.b p{ }', $scoped);
        $this->assertMatchesRegularExpression('/\.b r\{animation-name:"epub-[0-9a-f]{6}-spin"\}/', $scoped);

        // No keyframes are declared, so an animation name stays as it is.
        $this->assertStringContainsString('.b q{animation:x}', CssScope::scope('@font-face{font-family:A} q{animation:x}', '.b'));
    }

    public function testSanitizerDropsAForbiddenFunctionInASelector(): void
    {
        $this->assertSame('', CssSanitizer::sanitize('a:is(src(x)){color:red}', static fn (string $url): ?string => null));
    }
}
