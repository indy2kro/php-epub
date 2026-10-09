<?php

declare(strict_types=1);

namespace PhpEpub\Merge;

/**
 * What Merger::merge() did.
 */
final readonly class MergeReport
{
    /**
     * @param int $books How many books were merged.
     * @param int $files How many files the merged book's manifest lists (without the navigation document and NCX).
     * @param int $deduplicated How many files were left out because a byte-identical one was kept.
     * @param int $bytes The size of the extracted books together, as checked against the limit.
     * @param string $identifier The merged book's unique identifier.
     */
    public function __construct(
        public int $books,
        public int $files,
        public int $deduplicated,
        public int $bytes,
        public string $identifier
    ) {
    }
}
