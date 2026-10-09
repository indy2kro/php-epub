<?php

declare(strict_types=1);

namespace PhpEpub\Build;

/**
 * What BookBuilder accepts. Content that goes over a limit is refused with a BuildException (the chapters, and the
 * total size of everything given) or skipped with a warning (single images).
 */
final readonly class BuildLimits
{
    /**
     * @param int $maxChapters The most chapters (or comic pages) in a book.
     * @param int $maxTotalBytes The most bytes of everything given together: text, CSS, cover and images.
     * @param int $maxImageBytes The largest single image.
     * @param int $maxImages The most images given (used or not).
     * @param int $maxPixels The most pixels (width times height) of a single image, so a small file cannot expand
     *                       into a huge bitmap in a reading system.
     */
    public function __construct(
        public int $maxChapters = 1000,
        public int $maxTotalBytes = 100 * 1024 * 1024,
        public int $maxImageBytes = 20 * 1024 * 1024,
        public int $maxImages = 2000,
        public int $maxPixels = 100_000_000
    ) {
    }
}
