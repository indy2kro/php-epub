<?php

declare(strict_types=1);

namespace PhpEpub\Test\Support;

use PhpEpub\InvalidEpubException;
use PhpEpub\Util\PathResolver;

/**
 * A resolver that refuses every path, for the code that must cope with paths it cannot resolve.
 */
final class ThrowingPathResolver extends PathResolver
{
    public function resolve(string $rootDirectory, string $path): string
    {
        throw new InvalidEpubException("Path resolves outside the EPUB: {$path}");
    }
}
