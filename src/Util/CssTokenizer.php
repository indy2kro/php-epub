<?php

declare(strict_types=1);

namespace PhpEpub\Util;

/**
 * Splits CSS into tokens the way CSS Syntax Level 3 does where it matters for safety: comments are removed,
 * strings end at the matching quote and never run past a newline, unquoted url() values are single tokens, and
 * brackets are separate tokens, so rule boundaries found here are the ones a browser finds.
 *
 * Each token is [type, text] (the text is the source, so joining the texts gives the CSS back, comments aside):
 * "ws", "str" (with its quotes), "url" (a whole unquoted url(...)), "fn" (a function name and its "(", e.g.
 * "calc("), "other" (a word or one character), and the punctuation tokens "{", "}", "(", ")", "[", "]", ";", ",".
 *
 * @internal
 */
final class CssTokenizer
{
    /**
     * @return list<array{string, string}>|null The tokens; null when the CSS holds a string that is not closed on
     *                                          its line or a url() that is malformed or not closed (a browser would
     *                                          read the rest of such CSS differently from a naive parser).
     */
    public static function tokenize(string $css): ?array
    {
        $tokens = [];
        $length = strlen($css);
        $i = 0;
        while ($i < $length) {
            $c = $css[$i];
            if (ctype_space($c)) {
                $end = $i + strspn($css, " \t\n\r\f\v", $i);
                $tokens[] = ['ws', substr($css, $i, $end - $i)];
                $i = $end;
            } elseif ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $tokens[] = ['ws', ' '];
                $i = $end === false ? $length : $end + 2;
            } elseif ($c === '"' || $c === "'") {
                $end = self::stringEnd($css, $i);
                if ($end === null) {
                    return null;
                }

                $tokens[] = ['str', substr($css, $i, $end - $i)];
                $i = $end;
            } elseif (ctype_alnum($c) || $c === '_' || $c === '-' || ord($c) >= 0x80) {
                $end = $i + strspn($css, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-' . self::highBytes(), $i);
                $word = substr($css, $i, $end - $i);
                if (($css[$end] ?? '') !== '(') {
                    $tokens[] = ['other', $word];
                    $i = $end;

                    continue;
                }

                if (strtolower($word) === 'url') {
                    $after = $end + 1 + strspn($css, " \t\n\r\f", $end + 1);
                    if (($css[$after] ?? '') !== '"' && ($css[$after] ?? '') !== "'") {
                        $close = self::unquotedUrlEnd($css, $after);
                        if ($close === null) {
                            return null;
                        }

                        $tokens[] = ['url', substr($css, $i, $close - $i)];
                        $i = $close;

                        continue;
                    }
                }

                $tokens[] = ['fn', $word . '('];
                $i = $end + 1;
            } elseif (str_contains('{}()[];,', $c)) {
                $tokens[] = [$c, $c];
                $i++;
            } elseif ($c === '\\') {
                $tokens[] = ['other', substr($css, $i, 2)];
                $i += 2;
            } else {
                $tokens[] = ['other', $c];
                $i++;
            }
        }

        return $tokens;
    }

    /**
     * The value of a "url" token: "url( http://x/y )" is "http://x/y".
     */
    public static function urlValue(string $token): string
    {
        return trim(substr($token, 4, -1));
    }

    /**
     * The content of a "str" token without its quotes.
     */
    public static function stringValue(string $token): string
    {
        return substr($token, 1, -1);
    }

    /**
     * @return string The bytes 0x80 to 0xFF, which are parts of non-ASCII identifiers.
     */
    private static function highBytes(): string
    {
        static $bytes = null;

        return $bytes ??= implode('', array_map(chr(...), range(0x80, 0xFF)));
    }

    /**
     * The offset after the string that starts at $start; null when it is not closed before a line break or the end.
     */
    private static function stringEnd(string $css, int $start): ?int
    {
        $quote = $css[$start];
        $length = strlen($css);
        for ($i = $start + 1; $i < $length; $i++) {
            $c = $css[$i];
            if ($c === $quote) {
                return $i + 1;
            }

            if ($c === "\n" || $c === "\r" || $c === "\f") {
                return null;
            }

            if ($c === '\\') {
                // An escaped character (a line break continues the string) is never the end.
                $i++;
            }
        }

        return null;
    }

    /**
     * The offset after the ")" of an unquoted url whose value starts at $start; null when it is malformed (quotes,
     * parentheses, white space inside, control characters) or not closed.
     */
    private static function unquotedUrlEnd(string $css, int $start): ?int
    {
        $length = strlen($css);
        for ($i = $start; $i < $length; $i++) {
            $c = $css[$i];
            if ($c === ')') {
                return $i + 1;
            }

            if (ctype_space($c)) {
                $next = $i + strspn($css, " \t\n\r\f", $i);

                return ($css[$next] ?? '') === ')' ? $next + 1 : null;
            }

            if ($c === '"' || $c === "'" || $c === '(' || $c === '\\' || ord($c) < 0x20 || ord($c) === 0x7f) {
                return null;
            }
        }

        return null;
    }
}
