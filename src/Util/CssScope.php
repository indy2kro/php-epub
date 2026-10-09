<?php

declare(strict_types=1);

namespace PhpEpub\Util;

/**
 * Prefixes every selector of a stylesheet with a scope selector, so a book's CSS only styles the part of a
 * page that holds the book. "body", "html" and ":root" selectors name the scope itself.
 *
 * Rules inside @media, @supports and similar are scoped too; @font-face, @keyframes and @page rules are kept
 * as they are, and other at-rules are dropped. A selector that would reach the scope's siblings ("body ~ div",
 * "html + *") is dropped.
 *
 * The CSS is split into rules with CssTokenizer, so a string, comment or url() can never hide a rule boundary. A
 * sheet the tokenizer refuses (a string not closed on its line, a malformed url()) or whose brackets or blocks are
 * not balanced is dropped as a whole: the result is "".
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
        $tokens = CssTokenizer::tokenize($css);

        return $tokens === null ? '' : (self::rules($tokens, $scope, 0) ?? '');
    }

    /**
     * @param list<array{string, string}> $tokens
     *
     * @return string|null The scoped rules; null when the brackets or blocks are not balanced.
     */
    private static function rules(array $tokens, string $scope, int $depth): ?string
    {
        $output = '';
        $count = count($tokens);
        $i = 0;
        while ($i < $count) {
            // The prelude runs to the "{" of a block or the ";" of a statement, outside brackets.
            $prelude = [];
            $stack = [];
            for (; $i < $count; $i++) {
                $type = $tokens[$i][0];
                if ($stack === [] && ($type === '{' || $type === ';')) {
                    break;
                }

                if (! self::track($stack, $type) || ($stack === [] && $type === '}')) {
                    return null;
                }

                $prelude[] = $tokens[$i];
            }

            if ($stack !== []) {
                return null;
            }

            if ($i >= $count || $tokens[$i][0] === ';') {
                // A statement, or trailing junk.
                $i++;

                continue;
            }

            // The block ends at the "}" that matches its "{": a "}" inside parentheses does not end it.
            $stack = ['}'];
            $block = [];
            for ($i++; $i < $count; $i++) {
                $type = $tokens[$i][0];
                self::track($stack, $type);
                if ($stack === []) {
                    break;
                }

                $block[] = $tokens[$i];
            }

            if ($stack !== []) {
                return null;
            }

            $i++;
            $rule = self::rule($prelude, $block, $scope, $depth);
            if ($rule === null) {
                return null;
            }

            $output .= $rule;
        }

        return $output;
    }

    /**
     * Follows the nesting of brackets: an opening token is pushed with the token that closes it, a closing token
     * that matches the innermost opening one pops it, and any other closing token is just a token.
     *
     * @param list<string> $stack
     *
     * @return bool False when a closing token arrives with nothing open at all.
     */
    private static function track(array &$stack, string $type): bool
    {
        $closer = match ($type) {
            '{' => '}',
            '(', 'fn' => ')',
            '[' => ']',
            default => null,
        };
        if ($closer !== null) {
            $stack[] = $closer;
        } elseif (in_array($type, ['}', ')', ']'], true)) {
            if ($stack === []) {
                return false;
            }

            if (end($stack) === $type) {
                array_pop($stack);
            }
        }

        return true;
    }
    /**
     * @param list<array{string, string}> $prelude
     * @param list<array{string, string}> $block
     *
     * @return string|null The scoped rule ("" when it is dropped); null when the block is not balanced.
     */
    private static function rule(array $prelude, array $block, string $scope, int $depth): ?string
    {
        $text = trim(self::text($prelude));
        if ($text === '') {
            return '';
        }

        if ($text[0] === '@') {
            $name = strtolower((string) preg_replace('/^@([\w-]+).*$/s', '$1', $text));
            if (in_array($name, self::NESTING_AT_RULES, true) && $depth < self::MAX_DEPTH) {
                $inner = self::rules($block, $scope, $depth + 1);

                return $inner === null ? null : $text . '{' . $inner . "}\n";
            }

            return in_array($name, self::PASSTHROUGH_AT_RULES, true) ? $text . '{' . self::text($block) . "}\n" : '';
        }

        $selectors = [];
        foreach (self::splitSelectors($prelude) as $selector) {
            $scoped = self::scopeSelector($selector, $scope);
            $scoped === null || $selectors[] = $scoped;
        }

        return $selectors === [] ? '' : implode(',', $selectors) . '{' . self::text($block) . "}\n";
    }

    /**
     * @return string|null The scoped selector; null when it is dropped.
     */
    private static function scopeSelector(string $selector, string $scope): ?string
    {
        $selector = trim($selector);
        if (preg_match('/^(?:html\s+)?(?:body|html|:root)(?![\w-])/i', $selector, $match) === 1) {
            $rest = substr($selector, strlen($match[0]));
            // "body ~ div" would select the siblings of the scope, which belong to the page around it.
            if (preg_match('/^\s*[~+]/', $rest) === 1) {
                return null;
            }

            return $scope . $rest;
        }

        return preg_match('/^[~+]/', $selector) === 1 ? null : $scope . ' ' . $selector;
    }

    /**
     * The selectors of a comma-separated list; commas inside parentheses and brackets do not split.
     *
     * @param list<array{string, string}> $prelude
     *
     * @return list<string>
     */
    private static function splitSelectors(array $prelude): array
    {
        $selectors = [];
        $current = '';
        $depth = 0;
        foreach ($prelude as [$type, $text]) {
            if ($type === ',' && $depth === 0) {
                $selectors[] = $current;
                $current = '';

                continue;
            }

            $depth += in_array($type, ['(', '[', 'fn'], true) ? 1 : (in_array($type, [')', ']'], true) ? -1 : 0);
            $current .= $text;
        }

        $selectors[] = $current;

        return array_values(array_filter(array_map(trim(...), $selectors), static fn (string $selector): bool => $selector !== ''));
    }

    /**
     * @param list<array{string, string}> $tokens
     */
    private static function text(array $tokens): string
    {
        return implode('', array_column($tokens, 1));
    }
}
