<?php

declare(strict_types=1);

namespace PhpEpub\Util;

/**
 * Syntax checks for metadata values: language tags and dates. They only check the form of a
 * value, not that a language is registered.
 *
 * @internal
 */
final class MetadataSyntax
{
    /**
     * A well-formed BCP 47 tag (RFC 5646): language with extended language subtags, script, region,
     * variants, extensions and private use, or a private-use-only tag ("x-…").
     */
    private const string LANGUAGE_TAG = '/^(?:[a-z]{2,3}(?:-[a-z]{3}){0,3}(?:-[a-z]{4})?(?:-(?:[a-z]{2}|\d{3}))?'
        . '(?:-(?:[a-z0-9]{5,8}|\d[a-z0-9]{3}))*(?:-[a-wy-z0-9](?:-[a-z0-9]{2,8})+)*(?:-x(?:-[a-z0-9]{1,8})+)?'
        . '|x(?:-[a-z0-9]{1,8})+)$/Di';

    /**
     * W3CDTF: YYYY, YYYY-MM, YYYY-MM-DD, or a date-time with hours, minutes, optional (fractional)
     * seconds and a time zone (Z or +hh:mm).
     */
    private const string W3CDTF = '/^(?<year>\d{4})(?:-(?<month>0[1-9]|1[0-2])(?:-(?<day>0[1-9]|[12]\d|3[01])'
        . '(?:T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d+)?)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d))?)?)?$/D';

    /**
     * A SMIL clock value, the syntax of media:duration: a full clock value (hh:mm:ss, any number of hour
     * digits), a partial one (mm:ss), or a time count with an optional unit (h, min, s, ms), each with
     * an optional fraction of the last unit, e.g. "0:32:29.5", "32:29", "1949.5s" or "1500ms".
     */
    private const string SMIL_CLOCK_VALUE = '/^(?:\d+:[0-5]\d:[0-5]\d(?:\.\d+)?|[0-5]\d:[0-5]\d(?:\.\d+)?|\d+(?:\.\d+)?(?:h|min|s|ms)?)$/D';

    public static function isSmilClockValue(string $value): bool
    {
        return preg_match(self::SMIL_CLOCK_VALUE, $value) === 1;
    }

    public static function isLanguageTag(string $value): bool
    {
        return preg_match(self::LANGUAGE_TAG, $value) === 1;
    }

    public static function isW3cdtf(string $value): bool
    {
        if (preg_match(self::W3CDTF, $value, $parts) !== 1) {
            return false;
        }

        // The day must exist in that month (the pattern only allows 01-31).
        return ! isset($parts['month'], $parts['day']) || checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year']);
    }
}
