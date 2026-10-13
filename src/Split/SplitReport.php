<?php

declare(strict_types=1);

namespace PhpEpub\Split;

/**
 * What Splitter::splitWithReport() did.
 */
final readonly class SplitReport
{
    /**
     * @param list<string> $paths The paths of the parts, in order.
     * @param list<string> $warnings What could not be read from the book and was left out or kept as it was (the table of
     *                               contents, the landmarks, font obfuscation); empty when the book was read in full.
     */
    public function __construct(
        public array $paths,
        public array $warnings = []
    ) {
    }
}
