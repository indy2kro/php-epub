<?php

declare(strict_types=1);

namespace PhpEpub\Test\Support;

use PHPUnit\Framework\Assert;

/**
 * GD is optional for the library but some tests need it: to draw test images, and for the PDF engines, which need it
 * to embed raster images. The CI job without optional extensions runs the rest.
 */
final class RequiresGd
{
    public static function check(string $why): void
    {
        extension_loaded('gd') || Assert::markTestSkipped("The gd extension is needed {$why}.");
    }
}
