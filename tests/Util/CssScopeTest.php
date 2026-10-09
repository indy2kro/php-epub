<?php

declare(strict_types=1);

namespace PhpEpub\Test\Util;

use PhpEpub\Util\CssSanitizer;
use PhpEpub\Util\CssScope;
use PHPUnit\Framework\TestCase;

final class CssScopeTest extends TestCase
{
    public function testSelectorsAreScopedAndBodyNamesTheScope(): void
    {
        $css = 'body, html { margin: 0 } p, .a > b:not(.c, .d) { color: red } body.dark p { color: #fff } :root { --x: 1 } * { box-sizing: border-box }';

        $scoped = CssScope::scope($css, '.book');

        $this->assertStringContainsString(".book,.book{ margin: 0 }", $scoped);
        $this->assertStringContainsString('.book p,.book .a > b:not(.c, .d){ color: red }', $scoped);
        $this->assertStringContainsString('.book.dark p{ color: #fff }', $scoped);
        $this->assertStringContainsString('.book{ --x: 1 }', $scoped);
        $this->assertStringContainsString('.book *{ box-sizing: border-box }', $scoped);
    }

    public function testAtRulesAreScopedKeptOrDropped(): void
    {
        $css = '@media (min-width: 10px) { p { color: red } } @font-face { font-family: F; src: url("x") } @keyframes k { from { top: 0 } to { top: 1px } }'
            . ' @import "a.css"; @charset "utf-8"; @unknown { p { color: blue } } a[title="}"] { color: green }';

        $scoped = CssScope::scope($css, '.book');

        $this->assertStringContainsString('@media (min-width: 10px){.book p{ color: red }', $scoped);
        $this->assertStringContainsString('@font-face{ font-family: F; src: url("x") }', $scoped);
        $this->assertStringContainsString('@keyframes k{ from { top: 0 } to { top: 1px } }', $scoped);
        $this->assertStringContainsString('.book a[title="}"]{ color: green }', $scoped);
        $this->assertStringNotContainsString('@import', $scoped);
        $this->assertStringNotContainsString('@unknown', $scoped);
        $this->assertStringNotContainsString('blue', $scoped);
    }

    public function testBrokenCssDoesNotLoopOrThrow(): void
    {
        foreach (['', '{', '}', 'p {', 'p { color: red', '@media {', '{}{}', "p{content:'", str_repeat('{', 5000)] as $css) {
            $this->assertLessThanOrEqual(strlen($css) * 2 + 10, strlen(CssScope::scope($css, '.book')));
        }
    }

    public function testSanitizerDecodesEscapesAndRewritesUrls(): void
    {
        $css = '@import url(http://evil.example/a.css); p { background: u\\72l(http://evil.example/b.png); width: expression(alert(1)); behavior: url(x.htc); -moz-binding: url(y) }'
            . ' q { background: image-set("a.png" 1x); border-image: url( "ok.png" ) }';

        $sanitized = CssSanitizer::sanitize($css, static fn (string $url): ?string => $url === 'ok.png' ? 'data:,ok' : null);

        $this->assertStringNotContainsString('evil.example', $sanitized);
        $this->assertStringNotContainsString('@import', $sanitized);
        $this->assertStringNotContainsString('expression', $sanitized);
        $this->assertStringNotContainsString('behavior', $sanitized);
        $this->assertStringNotContainsString('-moz-binding', $sanitized);
        $this->assertStringContainsString('url("data:,ok")', $sanitized);
        $this->assertStringContainsString('background: none', $sanitized);
    }
    public function testAStringEndsAtANewlineSoItCannotHideARuleBoundary(): void
    {
        $css = "p{x:\"\n} body{background:red} q{y:\"}";

        $this->assertSame('', CssScope::scope($css, '.book'));
        $this->assertSame('', CssSanitizer::sanitize($css, static fn (string $url): ?string => null));
        foreach (["p{x:'\n} body{background:red}", "p{x:\"a\r} body{background:red}", "p{x:\"a\f} body{background:red}", 'p{x:"abc} body{background:red}'] as $other) {
            $this->assertSame('', CssScope::scope($other, '.book'));
        }
    }

    public function testBracketsKeepABlockOpen(): void
    {
        $scoped = CssScope::scope('p{x:( } body{background:red} )} q{color:blue}', '.book');

        $this->assertSame(".book p{x:( } body{background:red} )}\n.book q{color:blue}\n", $scoped);
        $this->assertSame('', CssScope::scope('p{x:( } body{background:red}', '.book'));
        $this->assertSame('', CssScope::scope('p{color:red}} body{background:red}', '.book'));
        $this->assertSame('', CssScope::scope('p)}', '.book'));
    }

    public function testSelectorsThatReachTheSiblingsOfTheScopeAreDropped(): void
    {
        $css = 'body ~ div{a:b} :root ~ *{a:b} html + *{a:b} html body ~ p{a:b} ~ q{a:b} + r{a:b} body > p{c:d} body p{e:f} body, body ~ div{g:h}';

        $scoped = CssScope::scope($css, '.book');

        $this->assertSame(".book > p{c:d}\n.book p{e:f}\n.book{g:h}\n", $scoped);
    }

    public function testRemoteLoadsAreNeutralised(): void
    {
        $resolve = static fn (string $url): ?string => $url === 'ok.png' ? 'data:,ok' : null;
        $payloads = [
            'p{background:url(http://evil.example/x.png',
            'p{background:url(http://evil.example/x.png}',
            'p{background:url( http://evil.example/x.png a)}',
            'p{background:image-set(url(a.png) 1x, (x) 2x)}',
            'p{background:image-set("http://evil.example/a.png" 1x)}',
            'p{background:-webkit-image-set(url(http://evil.example/a.png) 1x)}',
            'p{background:image(http://evil.example/a.png)}',
            'p{background:cross-fade(url(http://evil.example/a.png), url(b.png), 50%)}',
            'p{background:element(#x)}',
            'p{background:src("http://evil.example/a.png")}',
            'p{background:url(\'http://evil.example/x.png\' foo)}',
        ];

        foreach ($payloads as $payload) {
            $sanitized = CssSanitizer::sanitize($payload . ' q{color:red}', $resolve);
            $this->assertStringNotContainsString('evil.example', $sanitized, $payload);
            $this->assertDoesNotMatchRegularExpression('/image-set|cross-fade|element\(|src\(|image\(/i', $sanitized, $payload);
        }

        $this->assertSame('p{border-image:url("data:,ok")}', CssSanitizer::sanitize('p{border-image:url(ok.png)}', $resolve));
        $this->assertSame('p{} q{color:red;}', CssSanitizer::sanitize('p{background:image-set(url(a.png) 1x, (x) 2x)} q{color:red;}', $resolve));
    }

    public function testElementsCannotLeaveTheirBox(): void
    {
        $resolve = static fn (string $url): ?string => null;

        $this->assertSame('p{color:red;}', CssSanitizer::sanitize('p{position:fixed;color:red;}', $resolve));
        $this->assertSame('p{color:red}', CssSanitizer::sanitize('p{ POSITION : Sticky !important;color:red}', $resolve));
        $this->assertSame('', CssSanitizer::sanitize('position:fixed', $resolve));
        $this->assertSame('inset:0;z-index:99999', CssSanitizer::sanitize('position:fixed; inset:0;z-index:99999', $resolve));
        $this->assertStringContainsString('position:absolute', CssSanitizer::sanitize('p{position:absolute}', $resolve));
        // Escapes cannot hide the keyword.
        $this->assertStringNotContainsString('fixed', CssSanitizer::sanitize('p{position:\66 ixed}', $resolve));
    }
}
