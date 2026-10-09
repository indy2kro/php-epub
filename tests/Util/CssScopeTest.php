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
            . ' q { background: image-set("a.png" 1x), url( "ok.png" ) }';

        $sanitized = CssSanitizer::sanitize($css, static fn (string $url): ?string => $url === 'ok.png' ? 'data:,ok' : null);

        $this->assertStringNotContainsString('evil.example', $sanitized);
        $this->assertStringNotContainsString('@import', $sanitized);
        $this->assertStringNotContainsString('expression', $sanitized);
        $this->assertStringNotContainsString('behavior', $sanitized);
        $this->assertStringNotContainsString('-moz-binding', $sanitized);
        $this->assertStringContainsString('url("data:,ok")', $sanitized);
        $this->assertStringContainsString('background: none', $sanitized);
    }
}
