<?php

declare(strict_types=1);

namespace PhpEpub\Util;

/**
 * Extracts local-resource candidates from CSS without evaluating stylesheets.
 *
 * @internal Shared by validation and reference analysis.
 */
final class CssReferences
{
    /**
     * @return array{list<string>, list<string>} Decoded references and the references written with CSS escapes.
     */
    public static function parse(string $css): array
    {
        $raw = [];
        if (preg_match_all('/url\(\s*(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'|((?:\\\\.|[^)"\'\s\\\\])*))\s*\)/is', $css, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) > 0) {
            foreach ($matches as $match) {
                $raw[] = (string) ($match[1] ?? $match[2] ?? $match[3] ?? '');
            }
        }

        if (preg_match_all('/@import\s+(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\')/is', $css, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) > 0) {
            foreach ($matches as $match) {
                $raw[] = (string) ($match[1] ?? $match[2] ?? '');
            }
        }

        if (preg_match_all('/image-set\(((?:[^()]|\([^()]*\))*)\)/i', $css, $sets) > 0) {
            foreach ($sets[1] as $set) {
                if (preg_match_all('/(["\'])((?:\\\\.|(?!\1)[^\\\\])*)\1/s', $set, $matches) > 0) {
                    array_push($raw, ...$matches[2]);
                }
            }
        }

        $references = [];
        $escaped = [];
        foreach ($raw as $value) {
            $decoded = self::unescape($value);
            $references[] = $decoded;
            if ($decoded !== $value) {
                $escaped[] = $decoded;
            }
        }

        return [$references, $escaped];
    }

    private static function unescape(string $value): string
    {
        if (! str_contains($value, '\\')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/s',
            static function (array $match): string {
                if ($match[1] !== '') {
                    $code = (int) hexdec($match[1]);

                    return $code > 0 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF) ? html_entity_decode('&#' . $code . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8') : "\u{FFFD}";
                }

                return $match[2];
            },
            $value
        );
    }
}
