<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use Closure;

/**
 * Makes untrusted CSS safe to embed: escapes are decoded (so nothing hides behind them), comments, @import,
 * @charset, @namespace, image-set() and legacy script hooks (expression(), behavior, -moz-binding) are
 * removed, and every url() is handed to a callback that decides what it may point to.
 *
 * @internal
 */
final class CssSanitizer
{
    /**
     * @param Closure(string): ?string $resolveUrl Called with each url() value; returns the URL to write instead,
     *                                             or null to drop it (the url() becomes "none").
     */
    public static function sanitize(string $css, Closure $resolveUrl): string
    {
        $css = (string) preg_replace_callback(
            '/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/su',
            static fn (array $match): string => $match[1] !== ''
                ? html_entity_decode('&#x' . $match[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : $match[2],
            $css
        );
        $css = str_replace('\\', '', $css);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $css = (string) preg_replace_callback(
            '/url\s*\(\s*(["\']?)(.*?)\1\s*\)/is',
            static function (array $match) use ($resolveUrl): string {
                $url = $resolveUrl(trim($match[2]));

                return $url === null ? 'none' : 'url("' . str_replace(['"', "\n", "\r", '<', '>'], ['%22', '', '', '%3C', '%3E'], $url) . '")';
            },
            $css
        );

        $css = (string) preg_replace('/(?:-webkit-)?image-set\s*\((?:[^()]|\([^()]*\))*\)/i', 'none', $css);
        $css = (string) preg_replace('/\bexpression\s*\(/i', 'none(', $css);
        $css = (string) preg_replace('/(?:behavior|-moz-binding)\s*:[^;}]*;?/i', '', $css);
        $css = (string) preg_replace('/@(?:import|charset|namespace)\b[^;{]*;?/i', '', $css);

        return trim($css);
    }
}
