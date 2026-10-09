<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use Closure;

/**
 * Makes untrusted CSS safe to embed: escapes are decoded (so nothing hides behind them), comments, @import,
 * @charset, @namespace and legacy script hooks (expression(), behavior, -moz-binding) are removed, every url() is
 * handed to a callback that decides what it may point to, and declarations that could load a resource in another
 * way (image-set(), image(), cross-fade(), element(), src(), a url() that is not a plain string or URL) or that
 * set position to anything but a literal static, relative or absolute are dropped.
 *
 * The CSS is read with CssTokenizer, so strings, urls and brackets are understood as a browser reads them. CSS
 * with a string that is not closed on its line, or a malformed or unclosed url(), is dropped entirely (the result
 * is ""): such CSS is read differently by different parsers.
 *
 * @internal
 */
final class CssSanitizer
{
    /**
     * Functions that can load a resource besides url(), or run code.
     */
    private const array FORBIDDEN_FUNCTIONS = ['image-set', '-webkit-image-set', 'image', 'cross-fade', '-webkit-cross-fade', 'element', '-moz-element', 'src', 'expression'];

    /**
     * @param Closure(string): ?string $resolveUrl Called with each url() value; returns the URL to write instead,
     *                                             or null to drop it (the url() becomes "none").
     */
    public static function sanitize(string $css, Closure $resolveUrl): string
    {
        $decoded = preg_replace_callback(
            '/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/su',
            static fn (array $match): string => $match[1] !== ''
                ? html_entity_decode('&#x' . $match[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : $match[2],
            $css
        );
        $css = str_replace('\\', '', $decoded ?? $css);
        $css = preg_replace('/(?:behavior|-moz-binding)\s*:[^;}]*;?/i', '', $css) ?? '';
        $css = preg_replace('/@(?:import|charset|namespace)\b[^;{]*;?/i', '', $css) ?? '';

        $tokens = CssTokenizer::tokenize($css);

        return $tokens === null ? '' : trim(self::rewrite($tokens, $resolveUrl));
    }

    /**
     * @param list<array{string, string}> $tokens
     * @param Closure(string): ?string $resolveUrl
     */
    private static function rewrite(array $tokens, Closure $resolveUrl): string
    {
        /** @var list<string> $out */
        $out = [];
        $declaration = 0;
        $drop = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$type, $text] = $tokens[$i];

            if ($type === 'url') {
                $url = $resolveUrl(CssTokenizer::urlValue($text));
                $out[] = $url === null ? 'none' : self::urlFunction($url);
            } elseif ($type === 'fn' && strtolower($text) === 'url(') {
                // url("...") : the string and the closing parenthesis must follow, with white space at most.
                $j = $i + 1;
                $j += ($tokens[$j][0] ?? '') === 'ws' ? 1 : 0;
                $string = ($tokens[$j][0] ?? '') === 'str' ? $tokens[$j][1] : null;
                $k = $j + 1 + (($tokens[$j + 1][0] ?? '') === 'ws' ? 1 : 0);
                if ($string === null || ($tokens[$k][0] ?? '') !== ')') {
                    $drop = true;
                    $out[] = $text;

                    continue;
                }

                $url = $resolveUrl(trim(CssTokenizer::stringValue($string)));
                $out[] = $url === null ? 'none' : self::urlFunction($url);
                $i = $k;
            } elseif ($type === 'fn' && in_array(strtolower(rtrim($text, '(')), self::FORBIDDEN_FUNCTIONS, true)) {
                $drop = true;
                $out[] = $text;
            } elseif ($type === ';' || $type === '}') {
                if ($drop || self::isPositionOut(implode('', array_slice($out, $declaration)))) {
                    array_splice($out, $declaration);
                    $out[] = $type === '}' ? '}' : '';
                } else {
                    $out[] = $text;
                }

                $declaration = count($out);
                $drop = false;
            } elseif ($type === '{') {
                if ($drop) {
                    // A forbidden function in a selector or at-rule prelude: nothing sensible is left to keep.
                    return '';
                }

                $out[] = $text;
                $declaration = count($out);
            } else {
                $out[] = $text;
            }
        }

        // A last declaration without ";" or "}" (a style attribute).
        if ($drop || self::isPositionOut(implode('', array_slice($out, $declaration)))) {
            array_splice($out, $declaration);
        }

        return implode('', $out);
    }

    /**
     * Whether a declaration sets "position" to anything but a literal static, relative or absolute (fixed and sticky
     * would take an element out of the box the book is shown in; var(), env() and attr() hide the value).
     */
    private static function isPositionOut(string $declaration): bool
    {
        return preg_match('/^\s*position\s*:/i', $declaration) === 1
            && preg_match('/^\s*position\s*:\s*(?:static|relative|absolute)\s*(?:!\s*important)?\s*$/i', $declaration) !== 1;
    }

    private static function urlFunction(string $url): string
    {
        return 'url("' . str_replace(['"', "\n", "\r", '<', '>', '\\'], ['%22', '', '', '%3C', '%3E', '%5C'], $url) . '")';
    }
}
