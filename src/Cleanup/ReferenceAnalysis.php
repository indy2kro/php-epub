<?php

declare(strict_types=1);

namespace PhpEpub\Cleanup;

/**
 * The result of ReferenceGraph: which manifest files a book reaches from its roots.
 */
final readonly class ReferenceAnalysis
{
    /**
     * @param list<string> $reachable Manifest paths (relative to the book root, sorted) reachable from the roots.
     * @param list<string> $unreachable Manifest paths (sorted) that are not.
     * @param array<string, list<string>> $references The manifest files each reachable file refers to directly.
     * @param list<string> $unparsable Reachable documents that could not be parsed; the files they might refer to
     *                                 (any whose name appears in their text) are counted as reachable.
     * @param list<string> $unmanifested Files that exist in the book and are referenced by a reachable document
     *                                   but are not listed in the manifest (sorted).
     */
    public function __construct(
        public array $reachable,
        public array $unreachable,
        public array $references = [],
        public array $unparsable = [],
        public array $unmanifested = []
    ) {
    }

    public function isReachable(string $path): bool
    {
        return in_array($path, $this->reachable, true);
    }
}
