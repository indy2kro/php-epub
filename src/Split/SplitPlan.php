<?php

declare(strict_types=1);

namespace PhpEpub\Split;

use PhpEpub\Exception;

/**
 * How Splitter cuts a book into parts. Build one with a named constructor, then adjust it with the with*() methods.
 *
 * ```
 * SplitPlan::byToc()                       // one part per top-level table of contents entry
 * SplitPlan::everySpineItems(10)           // ten reading order items per part
 * SplitPlan::bySpineRanges([[0, 4], [5, 9]]) // explicit parts (zero-based, inclusive indexes into the reading order)
 * SplitPlan::byMaxBytes(5_000_000)         // as many items per part as fit in about 5 MB
 * ```
 */
final readonly class SplitPlan
{
    public const string DEFAULT_TITLE_PATTERN = '{title} (Part {n} of {total})';

    public const int DEFAULT_MAX_PARTS = 50;

    /**
     * @param list<array{int, int}> $ranges
     */
    private function __construct(
        public SplitKind $kind,
        public int $level = 1,
        public int $count = 0,
        public array $ranges = [],
        public int $maxBytes = 0,
        public string $titlePattern = self::DEFAULT_TITLE_PATTERN,
        public string $filePrefix = 'part',
        public ?\Closure $clock = null,
        public int $maxParts = self::DEFAULT_MAX_PARTS
    ) {
    }

    /**
     * One part per table of contents entry of a level (1: the top-level entries). Reading order items before the
     * first entry go with the first part, and an entry's part runs to the next entry's first item.
     *
     * @throws Exception If the level is below 1.
     */
    public static function byToc(int $level = 1): self
    {
        $level >= 1 || throw new Exception('The table of contents level must be at least 1');

        return new self(SplitKind::Toc, level: $level);
    }

    /**
     * A part for every $count items of the reading order (the last part may have fewer).
     *
     * @throws Exception If the count is below 1.
     */
    public static function everySpineItems(int $count): self
    {
        $count >= 1 || throw new Exception('A part needs at least one reading order item');

        return new self(SplitKind::Count, count: $count);
    }

    /**
     * Explicit parts: [first, last] pairs of zero-based, inclusive positions in the reading order, in order and
     * without overlap. Items outside every range are left out.
     *
     * @param list<array{int, int}> $ranges
     *
     * @throws Exception If there is no range, a range is empty or negative, or the ranges are not in order or overlap.
     */
    public static function bySpineRanges(array $ranges): self
    {
        $ranges !== [] || throw new Exception('A split plan needs at least one range');

        $previousEnd = -1;
        foreach ($ranges as [$start, $end]) {
            $start >= 0 && $end >= $start || throw new Exception("A range must be [first, last] with 0 <= first <= last, got: [{$start}, {$end}]");
            $start > $previousEnd || throw new Exception("The ranges must be in order and must not overlap: [{$start}, {$end}]");
            $previousEnd = $end;
        }

        return new self(SplitKind::Ranges, ranges: $ranges);
    }

    /**
     * Parts of about $bytes bytes: reading order items are added to a part while the part's files (the documents
     * and the images, stylesheets and fonts they use, counted once, uncompressed) stay within the limit. A single
     * item larger than the limit gets a part of its own, so a part can exceed it.
     *
     * @throws Exception If the limit is below 1.
     */
    public static function byMaxBytes(int $bytes): self
    {
        $bytes >= 1 || throw new Exception('The size limit must be at least 1 byte');

        return new self(SplitKind::Bytes, maxBytes: $bytes);
    }

    /**
     * The title of a part: "{title}" is the book's title, "{n}" the part's number and "{total}" the number of parts.
     * A book that ends up in one part keeps its title.
     */
    public function withTitlePattern(string $pattern): self
    {
        return new self($this->kind, $this->level, $this->count, $this->ranges, $this->maxBytes, $pattern, $this->filePrefix, $this->clock, $this->maxParts);
    }

    /**
     * The file name prefix: parts are written as "{prefix}-01.epub", "{prefix}-02.epub", …
     *
     * @throws Exception If the prefix is empty or has characters other than letters, digits, ".", "_" and "-".
     */
    public function withFilePrefix(string $prefix): self
    {
        preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $prefix) === 1 || throw new Exception("Invalid file name prefix: {$prefix}");

        return new self($this->kind, $this->level, $this->count, $this->ranges, $this->maxBytes, $this->titlePattern, $prefix, $this->clock, $this->maxParts);
    }

    /**
     * The clock that gives the dcterms:modified date of the parts (now by default).
     *
     * @param \Closure(): \DateTimeInterface $clock
     */
    public function withClock(\Closure $clock): self
    {
        return new self($this->kind, $this->level, $this->count, $this->ranges, $this->maxBytes, $this->titlePattern, $this->filePrefix, $clock, $this->maxParts);
    }

    /**
     * The most parts split() writes (50 by default); a plan that would produce more is refused before anything is written.
     *
     * @throws Exception If the limit is below 1.
     */
    public function withMaxParts(int $maxParts): self
    {
        $maxParts >= 1 || throw new Exception('The part limit must be at least 1');

        return new self($this->kind, $this->level, $this->count, $this->ranges, $this->maxBytes, $this->titlePattern, $this->filePrefix, $this->clock, $maxParts);
    }
}
