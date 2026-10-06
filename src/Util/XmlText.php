<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use PhpEpub\Exception;

/**
 * Guards strings written into package documents.
 *
 * libxml does not reject bad input when building a tree: invalid UTF-8 is written
 * as raw bytes (the saved file no longer parses) and control characters are
 * dropped silently. Values are therefore checked before they reach the document.
 */
final class XmlText
{
    /**
     * Characters allowed by XML 1.0: tab, newline, carriage return and everything
     * from U+0020 except surrogates, U+FFFE and U+FFFF.
     */
    private const string INVALID_CHARACTER = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    /**
     * @throws Exception If any value is not valid UTF-8 or contains a character XML cannot hold.
     */
    public static function assertValid(string ...$values): void
    {
        foreach ($values as $value) {
            // preg_match() returns false for invalid UTF-8 in /u mode.
            if (preg_match(self::INVALID_CHARACTER, $value) !== 0) {
                throw new Exception(
                    'Value is not valid XML text (it must be UTF-8 without control characters): ' . self::describe($value)
                );
            }
        }
    }

    /**
     * A printable, length-limited rendering of a rejected value for error messages.
     */
    private static function describe(string $value): string
    {
        $printable = (string) preg_replace_callback(
            '/[^\x20-\x7E]/',
            static fn (array $match): string => sprintf('\\x%02X', ord($match[0])),
            substr($value, 0, 40)
        );

        return '"' . $printable . (strlen($value) > 40 ? '..."' : '"');
    }
}
