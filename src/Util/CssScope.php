<?php

declare(strict_types=1);

namespace PhpEpub\Util;

/**
 * Prefixes every selector of a stylesheet with a scope selector, so a book's CSS only styles the part of a
 * page that holds the book. "body", "html" and ":root" selectors name the scope itself.
 *
 * - Rules inside @media, @supports and similar are scoped too; @layer blocks lose their name; @page rules are kept
 *   as they are; @property, @font-palette-values and other at-rules are dropped.
 * - Names that CSS declares globally (the families of @font-face, the names of @keyframes and @counter-style) are
 *   renamed to "epub-<hash>-<name>", and their uses in the book's declarations (font-family, font, animation,
 *   animation-name, list-style, list-style-type, content) follow, so a book can neither replace the host page's
 *   fonts, animations and counter styles nor see theirs defined by name.
 * - A selector that would reach the scope's siblings ("body ~ div", "html + *") is dropped, and so is any rule whose
 *   prelude has brackets that do not match ("`:is(])`"): parsers disagree on where its selectors end.
 *
 * The CSS is split into rules with CssTokenizer, so a string, comment or url() can never hide a rule boundary. A
 * sheet the tokenizer refuses (a string not closed on its line, a malformed url()) or whose brackets or blocks are
 * not balanced is dropped as a whole: the result is "".
 *
 * @internal
 *
 * @phpstan-type Token array{string, string}
 * @phpstan-type Rule array{text: string, prelude: list<Token>, block: list<Token>, children: list<array<string, mixed>>}
 * @phpstan-type Names array{family: list<string>, keyframes: list<string>, counter: list<string>}
 * @phpstan-type Renames array{family: array<string, string>, keyframes: array<string, string>, counter: array<string, string>}
 */
final class CssScope
{
    private const int MAX_DEPTH = 8;

    private const array NESTING_AT_RULES = ['media', 'supports', 'document', 'container', 'layer'];

    /**
     * Properties whose values name font families, and those that name animations or counter styles.
     */
    private const array FAMILY_PROPERTIES = ['font-family', 'font'];

    private const array KEYFRAMES_PROPERTIES = ['animation', 'animation-name', '-webkit-animation', '-webkit-animation-name'];

    private const array COUNTER_PROPERTIES = ['list-style', 'list-style-type', 'content', 'fallback', 'extends', 'system'];

    public static function scope(string $css, string $scope): string
    {
        return self::scopeAll([$css], $scope);
    }

    /**
     * Scopes several stylesheets that belong together: a name declared in one is renamed in all of them. A sheet
     * that cannot be read safely is left out; the others are kept.
     *
     * @param list<string> $sheets
     */
    public static function scopeAll(array $sheets, string $scope): string
    {
        $parsed = [];
        $names = ['family' => [], 'keyframes' => [], 'counter' => []];
        foreach ($sheets as $css) {
            $tokens = CssTokenizer::tokenize($css);
            $rules = $tokens === null ? null : self::split($tokens, 0);
            if ($rules !== null) {
                self::collect($rules, $names);
                $parsed[] = $rules;
            }
        }

        $renames = self::renames($names, $scope);
        $output = '';
        foreach ($parsed as $rules) {
            $output .= self::render($rules, $scope, $renames);
        }

        return $output;
    }

    /**
     * The rules of a token list; null when the brackets or blocks are not balanced.
     *
     * @param list<Token> $tokens
     *
     * @return list<Rule>|null
     */
    private static function split(array $tokens, int $depth): ?array
    {
        $rules = [];
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
                self::track($stack, $tokens[$i][0]);
                if ($stack === []) {
                    break;
                }

                $block[] = $tokens[$i];
            }

            if ($stack !== []) {
                return null;
            }

            $i++;
            $text = trim(self::text($prelude));
            $children = [];
            if ($text !== '' && $text[0] === '@' && in_array(self::atRuleName($text), self::NESTING_AT_RULES, true) && $depth < self::MAX_DEPTH) {
                $children = self::split($block, $depth + 1);
                if ($children === null) {
                    return null;
                }
            }

            $rules[] = ['text' => $text, 'prelude' => $prelude, 'block' => $block, 'children' => $children];
        }

        return $rules;
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
     * Whether every bracket of a prelude is closed by its own kind, in order.
     *
     * @param list<Token> $tokens
     */
    private static function balanced(array $tokens): bool
    {
        $stack = [];
        foreach ($tokens as [$type]) {
            $closer = match ($type) {
                '{' => '}',
                '(', 'fn' => ')',
                '[' => ']',
                default => null,
            };
            if ($closer !== null) {
                $stack[] = $closer;
            } elseif (in_array($type, ['}', ')', ']'], true) && array_pop($stack) !== $type) {
                return false;
            }
        }

        return $stack === [];
    }

    private static function atRuleName(string $text): string
    {
        return strtolower((string) preg_replace('/^@([\w-]+).*$/s', '$1', $text));
    }

    /**
     * Notes the names declared by @font-face, @keyframes and @counter-style rules.
     *
     * @param list<Rule> $rules
     * @param Names $names
     */
    private static function collect(array $rules, array &$names): void
    {
        foreach ($rules as $rule) {
            $text = $rule['text'];
            if ($text === '' || $text[0] !== '@') {
                continue;
            }

            $name = self::atRuleName($text);
            if ($name === 'font-face') {
                foreach (self::declarations($rule['block']) as $declaration) {
                    if ($declaration['property'] === 'font-family' && ($family = self::unquote($declaration['value'])) !== '') {
                        $names['family'][] = $family;
                    }
                }
            } elseif (in_array($name, ['keyframes', '-webkit-keyframes'], true) && ($keyframes = self::unquote((string) preg_replace('/^@[\w-]+\s*/', '', $text))) !== '') {
                $names['keyframes'][] = $keyframes;
            } elseif ($name === 'counter-style' && ($counter = self::unquote((string) preg_replace('/^@[\w-]+\s*/', '', $text))) !== '') {
                $names['counter'][] = $counter;
            } else {
                /** @var list<Rule> $children */
                $children = $rule['children'];
                self::collect($children, $names);
            }
        }
    }

    /**
     * The new name of each declared name.
     *
     * @param Names $names
     *
     * @return Renames
     */
    private static function renames(array $names, string $scope): array
    {
        $renames = ['family' => [], 'keyframes' => [], 'counter' => []];
        foreach ($names as $kind => $list) {
            foreach ($list as $name) {
                $slug = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $name), '-');
                $key = $kind === 'family' ? strtolower($name) : $name;
                $renames[$kind][$key] = 'epub-' . substr(md5($scope . '|' . $kind . '|' . $key), 0, 6) . ($slug === '' ? '' : '-' . substr($slug, 0, 40));
            }
        }

        return $renames;
    }

    /**
     * @param list<Rule> $rules
     * @param Renames $renames
     */
    private static function render(array $rules, string $scope, array $renames): string
    {
        $output = '';
        foreach ($rules as $rule) {
            // Brackets that do not match make the extent of the prelude depend on the parser: drop the rule.
            if ($rule['text'] === '' || ! self::balanced($rule['prelude'])) {
                continue;
            }

            $output .= $rule['text'][0] === '@' ? self::renderAtRule($rule, $scope, $renames) : self::renderStyleRule($rule, $scope, $renames);
        }

        return $output;
    }

    /**
     * @param Rule $rule
     * @param Renames $renames
     */
    private static function renderAtRule(array $rule, string $scope, array $renames): string
    {
        $text = $rule['text'];
        $name = self::atRuleName($text);
        /** @var list<Rule> $children */
        $children = $rule['children'];

        if ($name === 'layer') {
            // The layer's name is global: the rules stay, in an anonymous layer.
            return '@layer{' . self::render($children, $scope, $renames) . "}\n";
        }

        if (in_array($name, self::NESTING_AT_RULES, true)) {
            return $text . '{' . self::render($children, $scope, $renames) . "}\n";
        }

        $block = self::rewriteBlock($rule['block'], $renames);
        if ($name === 'font-face') {
            return '@font-face{' . $block . "}\n";
        }

        $declared = trim((string) preg_replace('/^@[\w-]+\s*/', '', $text));
        if (in_array($name, ['keyframes', '-webkit-keyframes'], true)) {
            $new = $renames['keyframes'][self::unquote($declared)] ?? null;

            return $new === null ? '' : '@' . $name . ' ' . $new . '{' . self::text($rule['block']) . "}\n";
        }

        if ($name === 'counter-style') {
            $new = $renames['counter'][self::unquote($declared)] ?? null;

            return $new === null ? '' : '@counter-style ' . $new . '{' . $block . "}\n";
        }

        return $name === 'page' ? $text . '{' . self::text($rule['block']) . "}\n" : '';
    }

    /**
     * @param Rule $rule
     * @param Renames $renames
     */
    private static function renderStyleRule(array $rule, string $scope, array $renames): string
    {
        $selectors = [];
        foreach (self::splitSelectors($rule['prelude']) as $selector) {
            $scoped = self::scopeSelector($selector, $scope);
            $scoped === null || $selectors[] = $scoped;
        }

        return $selectors === [] ? '' : implode(',', $selectors) . '{' . self::rewriteBlock($rule['block'], $renames) . "}\n";
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
     * The selectors of a comma-separated list; commas inside parentheses and brackets do not split. The prelude
     * must be balanced (see balanced()).
     *
     * @param list<Token> $prelude
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
     * The declarations of a block (those at its top level) as property (lower case) and value text.
     *
     * @param list<Token> $block
     *
     * @return list<array{property: string, value: string}>
     */
    private static function declarations(array $block): array
    {
        $declarations = [];
        foreach (self::declarationTokens($block) as $tokens) {
            if (preg_match('/^\s*([\w-]+)\s*:(.*)$/s', self::text($tokens), $match) === 1) {
                $declarations[] = ['property' => strtolower($match[1]), 'value' => trim($match[2])];
            }
        }

        return $declarations;
    }

    /**
     * The tokens of each top-level declaration of a block.
     *
     * @param list<Token> $block
     *
     * @return list<list<Token>>
     */
    private static function declarationTokens(array $block): array
    {
        $declarations = [];
        $current = [];
        $stack = [];
        foreach ($block as $token) {
            if ($token[0] === ';' && $stack === []) {
                $declarations[] = $current;
                $current = [];

                continue;
            }

            self::track($stack, $token[0]);
            $current[] = $token;
        }

        $current === [] || $declarations[] = $current;

        return $declarations;
    }

    /**
     * The block with the names its declarations use replaced by the renamed ones.
     *
     * @param list<Token> $block
     * @param Renames $renames
     */
    private static function rewriteBlock(array $block, array $renames): string
    {
        if ($renames === ['family' => [], 'keyframes' => [], 'counter' => []]) {
            return self::text($block);
        }

        $output = '';
        $declarations = self::declarationTokens($block);
        foreach ($declarations as $index => $tokens) {
            $output .= self::rewriteDeclaration($tokens, $renames) . ($index < count($declarations) - 1 || self::endsWithSemicolon($block) ? ';' : '');
        }

        return $output;
    }

    /**
     * @param list<Token> $block
     */
    private static function endsWithSemicolon(array $block): bool
    {
        for ($i = count($block) - 1; $i >= 0; $i--) {
            if ($block[$i][0] !== 'ws') {
                return $block[$i][0] === ';';
            }
        }

        return false;
    }

    /**
     * @param list<Token> $tokens
     * @param Renames $renames
     */
    private static function rewriteDeclaration(array $tokens, array $renames): string
    {
        $text = self::text($tokens);
        if (preg_match('/^(\s*)([\w-]+)(\s*:)(.*)$/s', $text, $match) !== 1) {
            return $text;
        }

        $property = strtolower($match[2]);
        $value = $match[4];
        if (in_array($property, self::FAMILY_PROPERTIES, true)) {
            foreach ($renames['family'] as $name => $new) {
                $words = array_map(static fn (string $word): string => preg_quote($word, '/'), preg_split('/\s+/', $name) ?: [$name]);
                $value = preg_replace('/(["\'])' . implode('\s+', $words) . '\1|(?<![\w-])' . implode('\s+', $words) . '(?![\w-])/i', '"' . $new . '"', $value) ?? $value;
            }
        } elseif (in_array($property, self::KEYFRAMES_PROPERTIES, true)) {
            $value = self::renameIdents($value, $renames['keyframes']);
        } elseif (in_array($property, self::COUNTER_PROPERTIES, true)) {
            $value = self::renameIdents($value, $renames['counter']);
        }

        return $match[1] . $match[2] . $match[3] . $value;
    }

    /**
     * Replaces the identifiers (and strings) of a value that are in $map; other strings are left alone.
     *
     * @param array<string, string> $map
     */
    private static function renameIdents(string $value, array $map): string
    {
        if ($map === []) {
            return $value;
        }

        $tokens = CssTokenizer::tokenize($value);
        if ($tokens === null) {
            return $value;
        }

        $output = '';
        foreach ($tokens as [$type, $text]) {
            if ($type === 'other' && isset($map[$text])) {
                $text = $map[$text];
            } elseif ($type === 'str' && isset($map[substr($text, 1, -1)])) {
                $text = '"' . $map[substr($text, 1, -1)] . '"';
            }

            $output .= $text;
        }

        return $output;
    }

    /**
     * A name as written in CSS, without quotes and with white space collapsed.
     */
    private static function unquote(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * @param list<Token> $tokens
     */
    private static function text(array $tokens): string
    {
        return implode('', array_column($tokens, 1));
    }
}
