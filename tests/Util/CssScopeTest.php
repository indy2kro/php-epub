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
        $this->assertMatchesRegularExpression('/@font-face\{ font-family: "epub-[0-9a-f]{6}-F"; src: url\("x"\) \}/', $scoped);
        $this->assertMatchesRegularExpression('/@keyframes epub-[0-9a-f]{6}-k\{ from \{ top: 0 \} to \{ top: 1px \} \}/', $scoped);
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
    public function testUnbalancedBracketsInASelectorCannotHideCommas(): void
    {
        $payload = ':is(]), .epub-host, .epub-book { contain:none!important; overflow:visible!important; position:static!important; isolation:auto!important } :is(]), #host { color:red!important }';

        $this->assertSame('', CssScope::scope($payload, '.epub-book'));

        $preludes = [
            ':is(])', ':is(]), #host', ':is([)), #host', ':not(a, #host', 'a), #host', '])(, #host', ')(, #host', ':is(\)), #host',
            ':is(a), :is(b)', '[a="x,y"], #host', ':is(a,b), #host', 'a:is(,), #host', 'a[b=")"], #host', ':is((]), #host', ':is([)], #host',
            ':is(a]), :where(b, #host', '@media (a]) , (b', 'a:has(b, c]), #host',
        ];
        foreach ($preludes as $prelude) {
            $scoped = CssScope::scope($prelude . '{color:red}', '.book');
            if ($scoped === '') {
                continue;
            }

            // Kept rules are balanced, so splitting at the commas outside brackets finds the selectors a browser does.
            $list = substr($scoped, 0, (int) strpos($scoped, '{'));
            foreach (preg_split('/,(?![^(\[]*[)\]])/', $list) ?: [] as $selector) {
                $this->assertStringStartsWith('.book', ltrim($selector), "{$prelude} => {$list}");
            }
        }
    }

    public function testDeclaredNamesAreRenamedSoTheBookCannotReachTheHostPage(): void
    {
        $css = '@font-face{font-family:"HostFont";src:local(x)} p{font-family:HostFont, serif; font: 12px "HostFont"} @keyframes spin{from{top:0}} .a{animation:spin 1s; animation-name: spin; content:"spin" counter(c, fancy)}'
            . ' @counter-style fancy{system:cyclic;symbols:"*"} ol{list-style:fancy;list-style-type:fancy} @property --x{syntax:"*";inherits:false} @layer base{p{color:red}} @layer a,b;'
            . ' @font-palette-values --p{font-family:HostFont} @font-face{font-family:Two Words;src:local(y)} q{font-family:Two Words, serif}';

        $scoped = CssScope::scope($css, '.book');

        $this->assertDoesNotMatchRegularExpression('/(?<![\w-])HostFont/', $scoped);
        $this->assertDoesNotMatchRegularExpression('/@keyframes spin|animation:\s*spin|(?<![\w-])fancy|Two Words/', $scoped);
        $this->assertStringNotContainsString('@property', $scoped);
        $this->assertStringNotContainsString('font-palette-values', $scoped);
        $this->assertStringContainsString('@layer{.book p{color:red}', $scoped);
        $this->assertStringNotContainsString('base', $scoped);
        $this->assertMatchesRegularExpression('/\.book p\{font-family:"epub-[0-9a-f]{6}-HostFont", serif; font: 12px "epub-[0-9a-f]{6}-HostFont"\}/', $scoped);
        $this->assertMatchesRegularExpression('/animation:epub-[0-9a-f]{6}-spin 1s; animation-name: epub-[0-9a-f]{6}-spin; content:"spin" counter\(c, epub-[0-9a-f]{6}-fancy\)/', $scoped);
        $this->assertMatchesRegularExpression('/list-style:epub-[0-9a-f]{6}-fancy;list-style-type:epub-[0-9a-f]{6}-fancy/', $scoped);
        $this->assertMatchesRegularExpression('/q\{font-family:"epub-[0-9a-f]{6}-Two-Words", serif\}/', str_replace('.book ', '', $scoped));
    }

    public function testRenamesAreConsistentAcrossSheets(): void
    {
        $scoped = CssScope::scopeAll(['@font-face{font-family:F;src:local(x)}', 'p{font-family:F}', 'x{{'], '.book');

        preg_match_all('/epub-[0-9a-f]{6}-F/', $scoped, $names);
        $this->assertCount(2, $names[0]);
        $this->assertSame($names[0][0], $names[0][1]);
        // The sheet that cannot be read is left out, the others stay.
        $this->assertStringContainsString('.book p{', $scoped);
    }

    public function testPositionIsKeptOnlyWithALiteralSafeValue(): void
    {
        $resolve = static fn (string $url): ?string => null;
        foreach (['var(--p)', 'env(x)', 'attr(x)', 'inherit', 'fixed', 'sticky', '-webkit-sticky', 'FIXED', 'var( --p ) !important', "\66 ixed"] as $value) {
            $this->assertSame('p{}', CssSanitizer::sanitize("p{position:{$value}}", $resolve), $value);
        }

        foreach (['static', 'relative', 'absolute', 'ABSOLUTE', 'relative !important'] as $value) {
            $this->assertSame("p{position:{$value}}", CssSanitizer::sanitize("p{position:{$value}}", $resolve), $value);
        }
    }
}
