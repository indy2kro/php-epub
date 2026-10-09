<?php

declare(strict_types=1);

namespace PhpEpub\Util;

/**
 * Prefixes every selector of a stylesheet with a scope selector, so a book's CSS only styles the part of a
 * page that holds the book. "body", "html" and ":root" selectors name the scope itself.
 *
 * Rules inside @media, @supports and similar are scoped too; @font-face, @keyframes and @page rules are kept
 * as they are, and other at-rules are dropped.
 *
 * @internal
 */
final class CssScope
{
    private const int MAX_DEPTH = 8;

    private const array NESTING_AT_RULES = ['media', 'supports', 'document', 'layer', 'container'];

    private const array PASSTHROUGH_AT_RULES = ['font-face', 'keyframes', '-webkit-keyframes', 'page', 'counter-style'];

    public static function scope(string $css, string $scope): string
    {
        return self::rules($css, $scope, 0);
    }

    private static function rules(string $css, string $scope, int $depth): string
    {
        $output = '';
        $length = strlen($css);
        $position = 0;

        while ($position < $length) {
            $end = self::find($css, $position, ';{');
            $prelude = trim(substr($css, $position, $end - $position));
            if ($end >= $length || $css[$end] === ';') {
                // A statement (or trailing junk) rather than a rule.
                $position = $end + 1;

                continue;
            }

            $close = self::find($css, $end + 1, '}', true);
            $block = substr($css, $end + 1, $close - $end - 1);
            $position = $close + 1;

            if ($prelude === '') {
                continue;
            }

            if ($prelude[0] === '@') {
                $name = strtolower((string) preg_replace('/^@([\w-]+).*$/s', '$1', $prelude));
                if (in_array($name, self::NESTING_AT_RULES, true) && $depth < self::MAX_DEPTH) {
                    $output .= $prelude . '{' . self::rules($block, $scope, $depth + 1) . "}\n";
                } elseif (in_array($name, self::PASSTHROUGH_AT_RULES, true)) {
                    $output .= $prelude . '{' . $block . "}\n";
                }

                continue;
            }

            $selectors = [];
            foreach (self::splitSelectors($prelude) as $selector) {
                $selectors[] = self::scopeSelector($selector, $scope);
            }

            if ($selectors !== []) {
                $output .= implode(',', $selectors) . '{' . $block . "}\n";
            }
        }

        return $output;
    }

    private static function scopeSelector(string $selector, string $scope): string
    {
        $selector = trim($selector);
        if (preg_match('/^(?:html\s+)?(?:body|html|:root)(?![\w-])/i', $selector, $match) === 1) {
            return $scope . substr($selector, strlen($match[0]));
        }

        return $scope . ' ' . $selector;
    }

    /**
     * The selectors of a comma-separated list; commas inside parentheses, brackets and strings do not split.
     *
     * @return list<string>
     */
    private static function splitSelectors(string $list): array
    {
        $selectors = [];
        $current = '';
        $depth = 0;
        $quote = '';
        for ($i = 0, $length = strlen($list); $i < $length; $i++) {
            $character = $list[$i];
            if ($quote !== '') {
                $quote = $character === $quote ? '' : $quote;
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($character === ',' && $depth === 0) {
                $selectors[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $selectors[] = $current;

        return array_values(array_filter(array_map(trim(...), $selectors), static fn (string $selector): bool => $selector !== ''));
    }

    /**
     * The offset of the first of $stops outside strings (or, with $matchBrace, of the "}" closing a block that
     * is already open), or the length of $css.
     */
    private static function find(string $css, int $from, string $stops, bool $matchBrace = false): int
    {
        $length = strlen($css);
        $quote = '';
        $depth = 0;
        for ($i = $from; $i < $length; $i++) {
            $character = $css[$i];
            if ($quote !== '') {
                $quote = $character === $quote ? '' : $quote;
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($matchBrace) {
                if ($character === '{') {
                    $depth++;
                } elseif ($character === '}') {
                    if ($depth === 0) {
                        return $i;
                    }

                    $depth--;
                }
            } elseif (str_contains($stops, $character)) {
                return $i;
            }
        }

        return $length;
    }
}
