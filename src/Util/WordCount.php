<?php

declare(strict_types=1);

namespace PhpEpub\Util;

/**
 * Counts the words of a text, Unicode-aware.
 *
 * Scripts written with spaces (Latin, Cyrillic, Greek, Arabic, Hangul, …) count one word per run of letters
 * and digits; an apostrophe or hyphen between letters ("don't", "well-known") and a point or comma between
 * digits ("3.5", "1,000") keep a word together.
 * Chinese and Japanese are written without spaces, so every Han, Hiragana and Katakana character counts as
 * one word (their punctuation does not count), the usual approximation for reading time. Scripts that also run words together without a
 * separator (Thai, Khmer, Lao, Burmese) are counted as one word per run of letters.
 *
 * @internal
 */
final class WordCount
{
    private const string UNSPACED = '/(?=\p{L})[\p{Han}\p{Hiragana}\p{Katakana}]/u';

    private const string WORD = "/\\p{N}+(?:[.,]\\p{N}+)+|[\\p{L}\\p{N}\\p{M}]+(?:['\u{2019}\\-][\\p{L}\\p{N}\\p{M}]+)*/u";

    public static function count(string $text): int
    {
        if ($text === '' || ! mb_check_encoding($text, 'UTF-8')) {
            return 0;
        }

        $unspaced = (int) preg_match_all(self::UNSPACED, $text);
        $rest = (string) preg_replace(self::UNSPACED, ' ', $text);

        return $unspaced + (int) preg_match_all(self::WORD, $rest);
    }
}
