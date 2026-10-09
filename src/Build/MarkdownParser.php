<?php

declare(strict_types=1);

namespace PhpEpub\Build;

/**
 * A small CommonMark-style Markdown to HTML converter for BookBuilder: ATX and setext headings, paragraphs, emphasis,
 * strong text, strikethrough, code spans, fenced and indented code, block quotes, nested lists, thematic breaks,
 * links, images, autolinks, hard breaks and GFM tables.
 *
 * Raw HTML in the Markdown is not passed through: "<" and "&" are always escaped, so the output holds only the elements
 * this class writes. Link and image targets are written as given; BookBuilder's sanitiser decides which survive.
 *
 * @internal
 */
final class MarkdownParser
{
    private const int MAX_DEPTH = 20;

    private const string HOLD = "\x1A";

    /**
     * Rendered pieces (code spans, links, escaped characters) held out of the way of the emphasis rules.
     *
     * @var list<string>
     */
    private array $held = [];

    /**
     * How often each heading id has been made, to number repeats.
     *
     * @var array<string, int>
     */
    private array $slugs = [];

    public function toHtml(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r", "\t", "\x00", self::HOLD], ["\n", "\n", '    ', '', ''], $markdown);

        return $this->blocks(explode("\n", $markdown), 0, false);
    }

    /**
     * @param list<string> $lines
     */
    private function blocks(array $lines, int $depth, bool $tight): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '<p>' . $this->escape(trim(implode(' ', $lines))) . '</p>';
        }

        $html = '';
        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
            } elseif (preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*([^`\s]*)[^`]*$/', $line, $match) === 1) {
                $html .= $this->fencedCode($lines, $i, $match[1], $match[2]);
            } elseif (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $match) === 1) {
                $html .= $this->heading(strlen($match[1]), $match[2] ?? '');
                $i++;
            } elseif ($this->isRule($line)) {
                $html .= "<hr/>\n";
                $i++;
            } elseif (preg_match('/^ {0,3}>/', $line) === 1) {
                $html .= $this->blockQuote($lines, $i, $depth);
            } elseif ($this->listMarker($line) !== null) {
                $html .= $this->list($lines, $i, $depth);
            } elseif (preg_match('/^ {4}/', $line) === 1) {
                $html .= $this->indentedCode($lines, $i);
            } elseif ($this->startsTable($lines, $i)) {
                $html .= $this->table($lines, $i);
            } else {
                $html .= $this->paragraph($lines, $i, $tight);
            }
        }

        return $html;
    }

    /**
     * @param list<string> $lines
     */
    private function fencedCode(array $lines, int &$i, string $fence, string $language): string
    {
        $count = count($lines);
        $character = preg_quote($fence[0], '/');
        $code = [];
        for ($i++; $i < $count; $i++) {
            if (preg_match('/^ {0,3}' . $character . '{' . strlen($fence) . ',}[ \t]*$/', $lines[$i]) === 1) {
                $i++;

                break;
            }

            $code[] = $lines[$i];
        }

        $class = preg_match('/^[A-Za-z0-9_+#-]{1,30}$/', $language) === 1 ? ' class="language-' . $language . '"' : '';

        return '<pre><code' . $class . '>' . $this->escape(implode("\n", $code)) . "</code></pre>\n";
    }

    private function isRule(string $line): bool
    {
        return preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $line) === 1;
    }

    /**
     * @param list<string> $lines
     */
    private function blockQuote(array $lines, int &$i, int $depth): string
    {
        $count = count($lines);
        $inner = [];
        for (; $i < $count; $i++) {
            if (preg_match('/^ {0,3}> ?(.*)$/', $lines[$i], $match) === 1) {
                $inner[] = $match[1];
            } elseif (trim($lines[$i]) !== '' && $inner !== [] && trim(end($inner)) !== '' && ! $this->startsBlock($lines[$i])) {
                // Lazy continuation of a paragraph.
                $inner[] = $lines[$i];
            } else {
                break;
            }
        }

        return "<blockquote>\n" . $this->blocks($inner, $depth + 1, false) . "</blockquote>\n";
    }

    /**
     * Whether a line can interrupt a paragraph.
     */
    private function startsBlock(string $line): bool
    {
        $marker = $this->listMarker($line);

        return preg_match('/^ {0,3}(?:`{3,}|~{3,}|#{1,6}(?:\s|$)|>)/', $line) === 1
            || $this->isRule($line)
            || ($marker !== null && $marker['content'] !== '' && (! $marker['ordered'] || $marker['number'] === 1));
    }

    /**
     * @return array{indent: int, ordered: bool, number: int, delimiter: string, width: int, content: string}|null
     */
    private function listMarker(string $line): ?array
    {
        if ($this->isRule($line) || preg_match('/^( {0,3})([-+*]|(\d{1,9})([.)]))(?:([ \t]+)(.*))?$/', $line, $match) !== 1) {
            return null;
        }

        $ordered = ($match[3] ?? '') !== '';
        $spaces = $match[5] ?? '';
        $content = $match[6] ?? '';
        // Content after five or more spaces is indented code inside the item; the marker is then followed by one space.
        $gap = $content === '' || strlen($spaces) > 4 ? 1 : strlen($spaces);
        if (strlen($spaces) > 4) {
            $content = substr($spaces, 1) . $content;
        }

        return [
            'indent' => strlen($match[1]),
            'ordered' => $ordered,
            'number' => $ordered ? (int) $match[3] : 0,
            'delimiter' => $ordered ? ($match[4] ?? '') : $match[2],
            'width' => strlen($match[1]) + strlen($match[2]) + $gap,
            'content' => $content,
        ];
    }

    /**
     * @param list<string> $lines
     */
    private function list(array $lines, int &$i, int $depth): string
    {
        $first = $this->listMarker($lines[$i]);
        if ($first === null) {
            return '';
        }

        $count = count($lines);
        $items = [];
        $loose = false;
        while ($i < $count) {
            $marker = $this->listMarker($lines[$i]);
            if ($marker === null || $marker['ordered'] !== $first['ordered'] || $marker['delimiter'] !== $first['delimiter']) {
                break;
            }

            $item = [$marker['content']];
            $pendingBlank = 0;
            for ($i++; $i < $count; $i++) {
                $line = $lines[$i];
                if (trim($line) === '') {
                    $pendingBlank++;

                    continue;
                }

                $indent = strlen($line) - strlen(ltrim($line, ' '));
                if ($indent >= $marker['width']) {
                    // Blank lines between the blocks of an item make the list loose.
                    $loose = $loose || ($pendingBlank > 0 && trim((string) end($item)) !== '');
                    for ($blank = 0; $blank < $pendingBlank; $blank++) {
                        $item[] = '';
                    }
                    $item[] = substr($line, $marker['width']);
                    $pendingBlank = 0;

                    continue;
                }

                if ($pendingBlank === 0 && trim((string) end($item)) !== '' && $this->listMarker($line) === null && ! $this->startsBlock($line)) {
                    $item[] = ltrim($line);

                    continue;
                }

                break;
            }

            $items[] = $item;
            if ($pendingBlank > 0 && $i < $count && ($next = $this->listMarker($lines[$i])) !== null && $next['ordered'] === $first['ordered'] && $next['delimiter'] === $first['delimiter']) {
                $loose = true;
            }
        }

        $tag = $first['ordered'] ? 'ol' : 'ul';
        $start = $first['ordered'] && $first['number'] !== 1 ? ' start="' . $first['number'] . '"' : '';
        $html = "<{$tag}{$start}>\n";
        foreach ($items as $item) {
            $html .= '<li>' . rtrim($this->blocks($item, $depth + 1, ! $loose), "\n") . "</li>\n";
        }

        return $html . "</{$tag}>\n";
    }


    /**
     * @param list<string> $lines
     */
    private function indentedCode(array $lines, int &$i): string
    {
        $count = count($lines);
        $code = [];
        for (; $i < $count; $i++) {
            if (preg_match('/^ {4}/', $lines[$i]) === 1) {
                $code[] = substr($lines[$i], 4);
            } elseif (trim($lines[$i]) === '') {
                $code[] = '';
            } else {
                break;
            }
        }

        return '<pre><code>' . $this->escape(rtrim(implode("\n", $code))) . "</code></pre>\n";
    }

    /**
     * @param list<string> $lines
     */
    private function startsTable(array $lines, int $i): bool
    {
        return str_contains($lines[$i], '|')
            && isset($lines[$i + 1])
            && preg_match('/^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/', $lines[$i + 1]) === 1
            && str_contains($lines[$i + 1], '-')
            && count(self::cells($lines[$i])) === count(self::cells($lines[$i + 1]));
    }

    /**
     * @param list<string> $lines
     */
    private function table(array $lines, int &$i): string
    {
        $header = self::cells($lines[$i]);
        $columns = count($header);
        $html = "<table>\n<thead>\n<tr>";
        foreach ($header as $cell) {
            $html .= '<th>' . $this->inline($cell) . '</th>';
        }

        $html .= "</tr>\n</thead>\n<tbody>\n";
        $count = count($lines);
        for ($i += 2; $i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|'); $i++) {
            $cells = array_slice(array_pad(self::cells($lines[$i]), $columns, ''), 0, $columns);
            $html .= '<tr>';
            foreach ($cells as $cell) {
                $html .= '<td>' . $this->inline($cell) . '</td>';
            }

            $html .= "</tr>\n";
        }

        return $html . "</tbody>\n</table>\n";
    }

    /**
     * @return list<string>
     */
    private static function cells(string $row): array
    {
        $row = trim($row);
        $row = str_starts_with($row, '|') ? substr($row, 1) : $row;
        $row = str_ends_with($row, '|') && ! str_ends_with($row, '\\|') ? substr($row, 0, -1) : $row;

        return array_map(static fn (string $cell): string => trim(str_replace('\\|', '|', $cell)), preg_split('/(?<!\\\\)\|/', $row) ?: []);
    }

    /**
     * @param list<string> $lines
     */
    private function paragraph(array $lines, int &$i, bool $tight): string
    {
        $count = count($lines);
        $text = [ltrim($lines[$i])];
        for ($i++; $i < $count; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                break;
            }

            if (preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $line, $match) === 1) {
                $level = $match[1][0] === '=' ? 1 : 2;
                $i++;

                return $this->heading($level, rtrim(implode("\n", $text)));
            }

            if ($this->startsBlock($line)) {
                break;
            }

            $text[] = ltrim($line);
        }

        $inline = $this->inline(rtrim(implode("\n", $text)));

        return $tight ? $inline . "\n" : '<p>' . $inline . "</p>\n";
    }

    /**
     * A heading with an id: the one written as "{#id}" at its end, else one made from its text ("Chapter One" is
     * "chapter-one"), numbered when the text repeats, so links such as [text](#chapter-one) work.
     */
    private function heading(int $level, string $text): string
    {
        $id = '';
        if (preg_match('/^(.*?)\s*\{#([A-Za-z][A-Za-z0-9_.-]*)\}\s*$/', $text, $match) === 1) {
            [$text, $id] = [$match[1], $match[2]];
        } else {
            $slug = trim((string) preg_replace('/[\s_-]+/', '-', strtolower((string) preg_replace('/[^A-Za-z0-9\s_-]+/', '', $text))), '-');
            if ($slug !== '') {
                $slug = preg_match('/^[A-Za-z]/', $slug) === 1 ? $slug : 'section-' . $slug;
                $count = $this->slugs[$slug] = ($this->slugs[$slug] ?? 0) + 1;
                $id = $count === 1 ? $slug : $slug . '-' . ($count - 1);
            }
        }

        return "<h{$level}" . ($id === '' ? '' : ' id="' . $id . '"') . '>' . $this->inline($text) . "</h{$level}>\n";
    }

    private function inline(string $text): string
    {
        $this->held = [];
        $html = $this->inlineText($text);

        return $this->release($html, false);
    }

    /**
     * Puts the held pieces back (they can hold other pieces: link text), as plain text for an attribute.
     */
    private function release(string $text, bool $plain): string
    {
        for ($round = 0; $round < self::MAX_DEPTH && str_contains($text, self::HOLD); $round++) {
            $text = (string) preg_replace_callback(
                '/' . self::HOLD . '(\d+)' . self::HOLD . '/',
                function (array $match) use ($plain): string {
                    $piece = $this->held[(int) $match[1]] ?? '';

                    return $plain ? strip_tags($piece) : $piece;
                },
                $text
            );
        }

        return $text;
    }

    private function inlineText(string $text): string
    {
        // Code spans first: nothing inside them is Markdown.
        $text = (string) preg_replace_callback(
            '/(?<!`)(`+)(?!`)(.{1,2000}?)(?<!`)\1(?!`)/s',
            function (array $match): string {
                $code = (string) preg_replace('/\s+/', ' ', $match[2]);
                $code = strlen($code) > 2 && $code[0] === ' ' && str_ends_with($code, ' ') ? substr($code, 1, -1) : $code;

                return $this->hold('<code>' . $this->escape($code) . '</code>');
            },
            $text
        );

        // Hard line breaks and backslash escapes.
        $text = (string) preg_replace_callback('/\\\\\n|\\\\([!-\/:-@\[-`{-~])/', fn (array $match): string => $this->hold(isset($match[1]) ? $this->escape($match[1]) : "<br/>\n"), $text);
        $text = (string) preg_replace_callback('/ {2,}\n/', fn (): string => $this->hold("<br/>\n"), $text);

        return $this->spans($this->escape($text));
    }

    /**
     * Images, links, autolinks and emphasis in text that is already escaped.
     */
    private function spans(string $text): string
    {
        // The bracketed text of a link is read as inline Markdown itself.
        $target = '((?:[^\s()]|\([^\s()]*\))+)(?:\s+&quot;(.*?)&quot;|\s+&#039;(.*?)&#039;)?';
        $text = (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(\s*' . $target . '\s*\)/',
            fn (array $match): string => $this->hold('<img src="' . $match[2] . '" alt="' . $this->plain($match[1]) . '"' . $this->title($match) . '/>'),
            $text
        );
        $text = (string) preg_replace_callback(
            '/\[((?:[^\[\]]|\[[^\[\]]*\])+)\]\(\s*' . $target . '\s*\)/',
            fn (array $match): string => $this->hold('<a href="' . $match[2] . '"' . $this->title($match) . '>' . $this->spans($match[1]) . '</a>'),
            $text
        );
        $text = (string) preg_replace_callback(
            '/&lt;((?:https?:\/\/|mailto:)[^\s&]+)&gt;/i',
            fn (array $match): string => $this->hold('<a href="' . $match[1] . '">' . $match[1] . '</a>'),
            $text
        );

        // Spans are at most 1000 characters (2000 for code): an opening marker without a closing one would otherwise
        // make every marker of a long text scan to its end (quadratic time).
        $rules = [
            '/\*\*\*(?=\S)(.{1,1000}?)(?<=\S)\*\*\*/s' => '<strong><em>$1</em></strong>',
            '/\*\*(?=\S)(.{1,1000}?)(?<=\S)\*\*/s' => '<strong>$1</strong>',
            '/(?<![\w])__(?=\S)(.{1,1000}?)(?<=\S)__(?![\w])/s' => '<strong>$1</strong>',
            '/\*(?=\S)(.{1,1000}?)(?<=\S)\*/s' => '<em>$1</em>',
            '/(?<![\w])_(?=\S)(.{1,1000}?)(?<=\S)_(?![\w])/s' => '<em>$1</em>',
            '/~~(?=\S)(.{1,1000}?)(?<=\S)~~/s' => '<del>$1</del>',
        ];

        return (string) preg_replace(array_keys($rules), array_values($rules), $text);
    }

    /**
     * Text for an attribute: held pieces as their text, no emphasis markers.
     */
    private function plain(string $text): string
    {
        return (string) preg_replace('/[*_~]+/', '', $this->release($text, true));
    }

    /**
     * @param array<int, string> $match
     */
    private function title(array $match): string
    {
        $title = ($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '');

        return $title === '' ? '' : ' title="' . str_replace('"', '&quot;', $title) . '"';
    }

    private function hold(string $html): string
    {
        $this->held[] = $html;

        return self::HOLD . (count($this->held) - 1) . self::HOLD;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
