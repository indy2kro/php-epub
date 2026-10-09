<?php

declare(strict_types=1);

namespace PhpEpub\Cleanup;

/**
 * Ready-made CleanupOptions, from the gentlest to the most aggressive.
 */
enum CleanupPreset: string
{
    /**
     * Unreferenced and stray files are removed and the archive is repacked at maximum deflate level.
     */
    case Light = 'light';

    /**
     * Light, plus images recompressed to at most 1600 px (longest side) at JPEG quality 80.
     */
    case Balanced = 'balanced';

    /**
     * Light, plus images recompressed to at most 1200 px at JPEG quality 65, and opaque PNGs converted to JPEG.
     */
    case Strong = 'strong';
}
