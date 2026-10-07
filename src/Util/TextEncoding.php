<?php

declare(strict_types=1);

namespace PhpEpub\Util;

/**
 * Turns the bytes of a content document into UTF-8 text for libxml's HTML parser.
 *
 * EPUB content documents are UTF-8 or UTF-16, so the encoding is taken from the byte order mark,
 * else from the XML declaration. The conversion needs mbstring or iconv, both optional; without
 * either (or for an encoding neither knows) the bytes are returned as they are, as if they were UTF-8.
 */
final class TextEncoding
{
    private const string UTF8_BOM = "\xEF\xBB\xBF";

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
     * @return string|null The UTF-8 text, or null when no extension can convert from $encoding.
     */
    private static function convert(string $content, string $encoding): ?string
    {
        if (function_exists('mb_convert_encoding') && in_array(strtolower($encoding), array_map(strtolower(...), mb_list_encodings()), true)) {
            $converted = mb_convert_encoding($content, 'UTF-8', $encoding);
        } else {
            $converted = function_exists('iconv') ? @iconv($encoding, 'UTF-8', $content) : false;
        }

        return $converted === false ? null : $converted;
    }
}
