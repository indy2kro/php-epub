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
     * @param list<string> $warnings What could not be read from a source and was left out or copied as it was (a table of
     *                               contents, landmarks, font obfuscation); empty when the sources were read in full.
     */
    public function __construct(
        public int $books,
        public int $files,
        public int $deduplicated,
        public int $bytes,
        public string $identifier,
        public array $warnings = []
    ) {
    }
}
