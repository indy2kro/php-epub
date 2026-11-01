<?php

declare(strict_types=1);

namespace PhpEpub\Util;

/**
 * Turns the bytes of a content document into UTF-8 text for libxml's HTML parser.
 *
 * EPUB content documents are UTF-8 or UTF-16, so the encoding is taken from the byte order mark,
 * else from the XML declaration. The conversion needs mbstring or iconv, both optional; without
 * either (or for an encoding neither knows) the bytes are returned as they are, as if they were UTF-8.
 *
 * @internal
 */
final class TextEncoding
{
    private const string UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * mbstring's pseudo-encodings and their aliases (lower case), and auto (detection): none of them is a character set.
     * HTML-ENTITIES would decode escaped markup into elements before the document is parsed and sanitised, and
     * PHP 8.2 deprecates most of them.
     */
    private const array PSEUDO_ENCODINGS = ['html-entities', 'html', 'base64', 'uuencode', 'quoted-printable', 'qprint', '7bit', '8bit', 'binary', 'auto'];

    /**
     * The content as UTF-8: without a byte order mark and, when it had to be converted, without the
     * XML declaration, which then names the wrong encoding.
     */
    public static function toUtf8(string $content): string
    {
        if (str_starts_with($content, self::UTF8_BOM)) {
            return substr($content, 3);
        }

        $encoding = match (true) {
            str_starts_with($content, "\xFF\xFE"), str_starts_with($content, "<\0") => 'UTF-16LE',
            str_starts_with($content, "\xFE\xFF"), str_starts_with($content, "\0<") => 'UTF-16BE',
            default => self::declaredEncoding($content),
        };
        $converted = $encoding === null ? null : self::convert($content, $encoding);

        return $converted === null
            ? $content
            : (string) preg_replace('/^(?:\xEF\xBB\xBF)?(?:<\?xml[^>]*\?>\s*)?/', '', $converted);
    }

    /**
     * The encoding named by the XML declaration, or null when there is none or it is UTF-8.
     */
    private static function declaredEncoding(string $content): ?string
    {
        if (preg_match('/^<\?xml[^>]*\sencoding\s*=\s*["\']([A-Za-z][A-Za-z0-9._-]*)["\']/', $content, $match) !== 1) {
            return null;
        }

        return in_array(strtolower($match[1]), ['utf-8', 'utf8'], true) ? null : $match[1];
    }

    /**
     * @return string|null The UTF-8 text, or null when no extension can convert from $encoding or it is not a
     *                     character set (see PSEUDO_ENCODINGS).
     */
    private static function convert(string $content, string $encoding): ?string
    {
        if (in_array(strtolower($encoding), self::PSEUDO_ENCODINGS, true)) {
            return null;
        }

        if (function_exists('mb_convert_encoding')) {
            try {
                // mbstring knows the aliases (latin1) too; an encoding it does not know is a ValueError.
                $converted = mb_convert_encoding($content, 'UTF-8', $encoding);
                if ($converted !== false) {
                    return $converted;
                }
            } catch (\ValueError) {
                // Left to iconv.
            }
        }

        $converted = function_exists('iconv') ? @iconv($encoding, 'UTF-8', $content) : false;

        return $converted === false ? null : $converted;
    }
}
