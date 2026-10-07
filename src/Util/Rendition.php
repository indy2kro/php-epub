<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use PhpEpub\Exception;
use SimpleXMLElement;

/**
 * The EPUB Rendition vocabulary: how a book is laid out (reflowable or fixed layout), shared by the
 * package metadata (rendition:layout, …) and the spine itemref properties (rendition:layout-pre-paginated, …).
 *
 * @internal
 */
final class Rendition
{
    /**
     * The prefix is reserved in EPUB 3.1 and later; a package of version "3.0" (which includes EPUB 3.2
     * and 3.3 packages) and older reading systems need it declared, which declaring it unconditionally covers.
     */
    private const string PREFIX = 'rendition';

    private const string URI = 'http://www.idpf.org/vocab/rendition/#';

    /**
     * The allowed values of each rendition aspect.
     */
    public const array VALUES = [
        'layout' => ['reflowable', 'pre-paginated'],
        'orientation' => ['auto', 'landscape', 'portrait'],
        'spread' => ['none', 'auto', 'landscape', 'portrait', 'both'],
        'flow' => ['auto', 'paginated', 'scrolled-continuous', 'scrolled-doc'],
    ];

    /**
     * @throws Exception If the aspect is not "layout", "orientation", "spread" or "flow", or the value is not allowed for it.
     */
    public static function assertValue(string $aspect, string $value): void
    {
        self::assertAspect($aspect);

        in_array($value, self::VALUES[$aspect], true) || throw new Exception("rendition:{$aspect} must be one of " . implode(', ', self::VALUES[$aspect]) . ", got: {$value}");
    }

    /**
     * @throws Exception If the aspect is not "layout", "orientation", "spread" or "flow".
     */
    public static function assertAspect(string $aspect): void
    {
        isset(self::VALUES[$aspect]) || throw new Exception("Not a rendition aspect: {$aspect}");
    }

    /**
     * Declares the rendition prefix in the package's prefix attribute (see PREFIX).
     */
    public static function declarePrefix(SimpleXMLElement $package): void
    {
        PackagePrefixes::declare($package, self::PREFIX, self::URI);
    }
}
