<?php

declare(strict_types=1);

namespace PhpEpub\Merge;

use PhpEpub\Exception;

/**
 * What Merger does and how much it accepts.
 */
final readonly class MergeOptions
{
    public const int DEFAULT_MAX_BOOKS = 10;

    public const int DEFAULT_MAX_TOTAL_BYTES = 24 * 1024 * 1024;

    /**
     * @param string|null $title The merged book's title; the first book's when null.
     * @param list<string>|null $authors The authors; the first book's when null.
     * @param string|null $language The main language; the first book's when null (the other books' languages follow it).
     * @param string|null $identifier The unique identifier; a new "urn:uuid:…" when null.
     * @param string|null $coverImage Bytes of the cover image; the first book's cover when null.
     * @param string $coverMediaType The media type of $coverImage, e.g. "image/jpeg".
     * @param bool $oneSectionPerBook True: one table of contents entry per book (titled with the book's title) with that
     *                                book's entries below it; false: the books' entries one after the other.
     * @param bool $deduplicate Keep one copy of byte-identical stylesheets (without url() or @import), fonts and raster images.
     * @param int $maxBooks The most books Merger accepts.
     * @param int $maxTotalBytes The most bytes Merger accepts: the size of the books' extracted files together.
     * @param \Closure(): \DateTimeInterface|null $clock Gives the time recorded as dcterms:modified; now when null.
     *
     * @throws Exception If a limit is below its minimum or the cover is not an image.
     */
    public function __construct(
        public ?string $title = null,
        public ?array $authors = null,
        public ?string $language = null,
        public ?string $identifier = null,
        public ?string $coverImage = null,
        public string $coverMediaType = 'image/jpeg',
        public bool $oneSectionPerBook = true,
        public bool $deduplicate = true,
        public int $maxBooks = self::DEFAULT_MAX_BOOKS,
        public int $maxTotalBytes = self::DEFAULT_MAX_TOTAL_BYTES,
        public ?\Closure $clock = null
    ) {
        $maxBooks >= 2 || throw new Exception('The book limit must be at least 2');
        $maxTotalBytes >= 1 || throw new Exception('The size limit must be at least 1 byte');
        $coverImage === null || str_starts_with($coverMediaType, 'image/') || throw new Exception("The cover must be an image, got: {$coverMediaType}");
    }
}
