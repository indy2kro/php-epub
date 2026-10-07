<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use Closure;
use PhpEpub\Exception;

/**
 * Checks the styles given to a PDF adapter, so a typo or a wrong value is reported
 * instead of being ignored.
 *
 * @internal Used by TCPDFAdapter and DompdfAdapter.
 */
final class PdfStyles
{
    /**
     * @param string $adapter The adapter's name, for messages.
     * @param array<string, 'string'|'number'|'bool'> $types The accepted styles and the type of each;
     *                                                       "number" is an int or a float.
     * @param array<string, mixed> $styles The styles given by the caller.
     * @param Closure(string): bool $isKnownPaperSize Whether the renderer knows a paper size.
     *
     * @throws Exception If a style is unknown, of the wrong type or has an unusable value.
     */
    public static function validate(string $adapter, array $types, array $styles, Closure $isKnownPaperSize): void
    {
        foreach ($styles as $name => $value) {
            $type = $types[$name] ?? throw new Exception(sprintf(
                '%s does not know the style "%s"; the styles are: %s',
                $adapter,
                $name,
                implode(', ', array_keys($types))
            ));

            $isValid = match ($type) {
                'string' => is_string($value),
                'number' => is_int($value) || is_float($value),
                'bool' => is_bool($value),
            };
            if (! $isValid) {
                throw new Exception(sprintf(
                    '%s style "%s" must be %s, %s given',
                    $adapter,
                    $name,
                    $type === 'number' ? 'a number (int or float)' : 'a ' . $type,
                    get_debug_type($value)
                ));
            }

            $problem = self::problemWith($name, $value, $isKnownPaperSize);
            if ($problem !== null) {
                throw new Exception(sprintf('%s style "%s" %s, %s given', $adapter, $name, $problem, var_export($value, true)));
            }
        }
    }

    /**
     * What is wrong with a value of the right type, or null.
     *
     * @param Closure(string): bool $isKnownPaperSize
     */
    private static function problemWith(string $name, mixed $value, Closure $isKnownPaperSize): ?string
    {
        $number = is_int($value) || is_float($value) ? $value : null;

        return match (true) {
            is_float($number) && ! is_finite($number) => 'must be a finite number',
            $name === 'font_size' && $number !== null && $number <= 0 => 'must be greater than zero',
            $number !== null && $number < 0 => 'must not be negative',
            $name === 'font' && $value === '' => 'must not be empty',
            $name === 'orientation' && ! in_array(is_string($value) ? strtolower($value) : '', ['portrait', 'landscape'], true) => 'must be "portrait" or "landscape"',
            $name === 'paper_size' && ! $isKnownPaperSize(is_string($value) ? $value : '') => 'is not a paper size the renderer knows',
            default => null,
        };
    }
}
