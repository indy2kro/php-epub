<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use DOMElement;
use DOMNode;
use DOMText;

/**
 * Converts sanitised HTML (see HtmlSanitizer) to CommonMark with GFM tables.
 *
 * The output never contains HTML: text is escaped so it cannot form markup, links keep only http, https and
 * mailto URLs (links inside the document lose their link), images are written with the src the sanitiser kept,
 * and tables that GFM cannot express (spans, nested content, ragged rows) become plain text.
 *
 * @internal
 */
final class HtmlToMarkdown
{
    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'body', 'caption', 'dd', 'details', 'div', 'dl', 'dt',
        'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'li', 'main',
        'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /**
     * Inside a table cell, these cannot be written on one line.
     */
    private const array COMPLEX_CELL_CONTENT = ['table', 'ul', 'ol', 'dl', 'blockquote', 'pre', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figure'];

    private const int MAX_DEPTH = 40;

    public function convert(DOMElement $root): string
    {
        return implode("\n\n", $this->blocks($root, 0));
    }

    /**
     * The blocks of an element's content: runs of inline content become paragraphs.
     *
     * @return list<string>
     */
    private function blocks(DOMNode $parent, int $depth): array
    {
        $blocks = [];
        $buffer = '';
        $flush = static function () use (&$blocks, &$buffer): void {
            $text = trim($buffer);
            if ($text !== '') {
                $blocks[] = self::escapeLineStarts($text);
            }

            $buffer = '';
        };

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMText) {
                $buffer .= self::text($child->data);
            } elseif ($child instanceof DOMElement) {
                if ($this->isBlock($child)) {
                    $flush();
                    array_push($blocks, ...$this->block($child, $depth + 1));
                } else {
                    $buffer .= $this->inline($child, $depth + 1);
                }
            }
        }

        $flush();

        return $blocks;
    }

    private function isBlock(DOMElement $element): bool
    {
        if (in_array(self::tag($element), self::BLOCKS, true)) {
            return true;
        }

        // An inline element wrapping blocks (a link around a div) is read as a container.
        foreach ($element->getElementsByTagName('*') as $descendant) {
            if (in_array(self::tag($descendant), self::BLOCKS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function block(DOMElement $element, int $depth): array
    {
        $tag = self::tag($element);
        if ($depth > self::MAX_DEPTH) {
            $text = trim(self::text($element->textContent));

            return $text === '' ? [] : [self::escapeLineStarts($text)];
        }

        switch ($tag) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $text = trim(self::replace('/\s*\n\s*/', ' ', $this->inlineChildren($element, $depth)));

                return $text === '' ? [] : [str_repeat('#', (int) $tag[1]) . ' ' . self::replace('/(#+)$/', '\\\\$1', $text)];
            case 'hr':
                return ['---'];
            case 'pre':
                return [$this->codeBlock($element)];
            case 'blockquote':
                $inner = implode("\n\n", $this->blocks($element, $depth));

                return $inner === '' ? [] : [implode("\n", array_map(
                    static fn (string $line): string => $line === '' ? '>' : '> ' . $line,
                    explode("\n", $inner)
                ))];
            case 'ul':
            case 'ol':
                $list = $this->listBlock($element, $depth);

                return $list === '' ? [] : [$list];
            case 'table':
                return $this->table($element, $depth);
            case 'dt':
                $text = trim($this->inlineChildren($element, $depth));

                return $text === '' ? [] : ['**' . $text . '**'];
            case 'figcaption':
            case 'caption':
                $text = trim($this->inlineChildren($element, $depth));

                return $text === '' ? [] : ['*' . $text . '*'];
            default:
                return $this->blocks($element, $depth);
        }
    }

    private function listBlock(DOMElement $list, int $depth): string
    {
        $ordered = self::tag($list) === 'ol';
        $number = $ordered && preg_match('/^-?\d{1,9}$/', $list->getAttribute('start')) === 1 ? max(0, (int) $list->getAttribute('start')) : 1;

        $items = [];
        foreach ($list->childNodes as $child) {
            if (! $child instanceof DOMElement || self::tag($child) !== 'li') {
                continue;
            }

            $marker = $ordered ? $number++ . '.' : '-';
            $content = '';
            foreach ($this->blocks($child, $depth) as $index => $block) {
                // A nested list continues the item without a blank line.
                $content .= $index === 0 ? $block : (preg_match('/^(?:[-]|\d+\.) /', $block) === 1 ? "\n" : "\n\n") . $block;
            }

            $indent = str_repeat(' ', strlen($marker) + 1);
            $lines = explode("\n", $content);
            $items[] = $marker . ' ' . $lines[0] . implode('', array_map(
                static fn (string $line): string => "\n" . ($line === '' ? '' : $indent . $line),
                array_slice($lines, 1)
            ));
        }

        return implode("\n", $items);
    }

    private function codeBlock(DOMElement $pre): string
    {
        $language = '';
        foreach ($pre->getElementsByTagName('code') as $code) {
            if (preg_match('/(?:^|\s)(?:language|lang)-([A-Za-z0-9_+#-]{1,30})(?:\s|$)/', $code->getAttribute('class'), $match) === 1) {
                $language = $match[1];
            }

            break;
        }

        $text = rtrim(str_replace(["\r\n", "\r"], "\n", $pre->textContent), "\n");
        preg_match_all('/`+/', $text, $runs);
        $fence = str_repeat('`', max(3, max(array_map(strlen(...), $runs[0] === [] ? [''] : $runs[0])) + 1));

        return $fence . $language . "\n" . $text . "\n" . $fence;
    }

    /**
     * @return list<string>
     */
    private function table(DOMElement $table, int $depth): array
    {
        /** @var list<list<string>> $rows */
        $rows = [];
        $simple = true;
        $caption = '';
        foreach ($table->getElementsByTagName('*') as $element) {
            $tag = self::tag($element);
            if ($tag === 'caption' && $caption === '') {
                $caption = trim($this->inlineChildren($element, $depth));
            } elseif ($tag === 'table') {
                $simple = false;
            } elseif ($tag === 'tr') {
                $cells = [];
                foreach ($element->childNodes as $cell) {
                    if (! $cell instanceof DOMElement || ! in_array(self::tag($cell), ['td', 'th'], true)) {
                        continue;
                    }

                    $simple = $simple && ! $this->isComplexCell($cell);
                    $cells[] = trim(self::replace('/\s*\n\s*/', ' ', $this->cellText($cell, $depth)));
                }

                $cells === [] || $rows[] = $cells;
            }
        }

        $blocks = $caption === '' ? [] : ['*' . $caption . '*'];
        if ($rows === []) {
            return $blocks;
        }

        $columns = count($rows[0]);
        $uniform = array_filter($rows, static fn (array $row): bool => count($row) !== $columns) === [];

        if (! $simple || ! $uniform) {
            $lines = array_map(static fn (array $row): string => implode(' | ', array_filter($row, static fn (string $cell): bool => $cell !== '')), $rows);
            $text = implode("  \n", array_filter($lines, static fn (string $line): bool => $line !== ''));

            return $text === '' ? $blocks : [...$blocks, self::escapeLineStarts($text)];
        }

        $lines = [self::tableRow($rows[0]), '| ' . implode(' | ', array_fill(0, $columns, '---')) . ' |'];
        foreach (array_slice($rows, 1) as $row) {
            $lines[] = self::tableRow($row);
        }

        return [...$blocks, implode("\n", $lines)];
    }

    /**
     * @param list<string> $cells
     */
    private static function tableRow(array $cells): string
    {
        // Pipes that text() has not already escaped (in code spans) must not end the cell.
        return '| ' . implode(' | ', array_map(
            static fn (string $cell): string => self::replace('/(?<!\\\\)\|/', '\\\\|', $cell),
            $cells
        )) . ' |';
    }

    private function isComplexCell(DOMElement $cell): bool
    {
        foreach (['colspan', 'rowspan'] as $span) {
            if ($cell->hasAttribute($span) && $cell->getAttribute($span) !== '1') {
                return true;
            }
        }

        foreach ($cell->getElementsByTagName('*') as $descendant) {
            if (in_array(self::tag($descendant), self::COMPLEX_CELL_CONTENT, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A cell's content on one line.
     */
    private function cellText(DOMElement $cell, int $depth): string
    {
        return implode(' ', array_map(
            static fn (string $block): string => str_replace("\n", ' ', $block),
            $this->blocks($cell, $depth)
        ));
    }

    private function inlineChildren(DOMNode $parent, int $depth): string
    {
        $text = '';
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text .= self::text($child->data);
            } elseif ($child instanceof DOMElement) {
                $text .= $this->inline($child, $depth + 1);
            }
        }

        return $text;
    }

    private function inline(DOMElement $element, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            return self::text($element->textContent);
        }

        $tag = self::tag($element);

        switch ($tag) {
            case 'br':
                return "  \n";
            case 'img':
                $path = $element->getAttribute('src');
                if ($path === '') {
                    return '';
                }

                return '![' . self::text($element->getAttribute('alt')) . '](' . self::destination($path) . ')';
            case 'a':
                $inner = $this->inlineChildren($element, $depth);
                $href = $element->getAttribute('href');
                if (trim($inner) === '' || $href === '' || str_starts_with($href, '#')) {
                    return $inner;
                }

                return '[' . trim($inner) . '](' . self::destination($href) . ')';
            case 'strong':
            case 'b':
                return $this->wrap($this->inlineChildren($element, $depth), '**');
            case 'em':
            case 'i':
            case 'cite':
            case 'dfn':
            case 'var':
                return $this->wrap($this->inlineChildren($element, $depth), '*');
            case 'del':
            case 's':
                return $this->wrap($this->inlineChildren($element, $depth), '~~');
            case 'code':
            case 'kbd':
            case 'samp':
                return self::codeSpan($element->textContent);
            case 'q':
                return '"' . $this->inlineChildren($element, $depth) . '"';
            case 'rt':
                return ' (' . trim($this->inlineChildren($element, $depth)) . ')';
            case 'rp':
            case 'wbr':
                return '';
            default:
                return $this->inlineChildren($element, $depth);
        }
    }

    /**
     * Emphasis delimiters must touch the text, so surrounding spaces move outside.
     */
    private function wrap(string $inner, string $delimiter): string
    {
        $core = trim($inner);
        if ($core === '') {
            return $inner === '' ? '' : ' ';
        }

        return substr($inner, 0, strlen($inner) - strlen(ltrim($inner))) . $delimiter . $core . $delimiter
            . substr($inner, strlen(rtrim($inner)));
    }

    private static function codeSpan(string $text): string
    {
        $text = trim(self::replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '';
        }

        preg_match_all('/`+/', $text, $runs);
        $fence = str_repeat('`', ($runs[0] === [] ? 0 : max(array_map(strlen(...), $runs[0]))) + 1);
        $pad = str_starts_with($text, '`') || str_ends_with($text, '`') ? ' ' : '';

        return $fence . $pad . $text . $pad . $fence;
    }

    /**
     * Text of a text node: white space collapsed, characters that could form Markdown escaped.
     */
    private static function text(string $text): string
    {
        $text = self::replace('/[\s\x{00A0}]+/u', ' ', $text);

        return self::replace('/[\\\\`*_\[\]<>&~|]/', '\\\\$0', $text);
    }

    /**
     * Markers at the start of a line (heading, list, rule) are escaped so a paragraph stays a paragraph.
     */
    private static function escapeLineStarts(string $text): string
    {
        $text = self::replace('/^( {0,3})(\d{1,9})([.)])(?=\s|$)/m', '$1$2\\\\$3', $text);

        return self::replace('/^( {0,3})(#{1,6}(?=\s|$)|[-+](?=\s|$)|[-=](?=[-=]*\s*$))/m', '$1\\\\$2', $text);
    }

    /**
     * A link or image destination with the characters that end or confuse it percent-encoded.
     */
    private static function destination(string $url): string
    {
        return self::replaceCallback(
            '/[\x00-\x20\x7f()<>\\\\]/',
            static fn (array $match): string => sprintf('%%%02X', ord($match[0])),
            trim($url)
        );
    }

    /**
     * preg_replace() that keeps the text when the engine fails (a limit was hit): content is never dropped.
     */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }

    /**
     * preg_replace_callback() that keeps the text when the engine fails, like replace().
     *
     * @param \Closure(array<int|string, string>): string $callback
     */
    private static function replaceCallback(string $pattern, \Closure $callback, string $subject): string
    {
        return preg_replace_callback($pattern, $callback, $subject) ?? $subject;
    }

    private static function tag(DOMElement $element): string
    {
        return strtolower($element->localName ?? $element->nodeName);
    }
}
