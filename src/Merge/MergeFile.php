<?php

declare(strict_types=1);

namespace PhpEpub\Merge;

/**
 * One file of a source book on its way into the merged book.
 *
 * @internal
 */
final class MergeFile
{
    public ?int $aliasOf = null;

    public string $newId = '';

    public function __construct(
        public readonly int $book,
        public readonly string $oldId,
        public readonly string $oldPath,
        public readonly string $newPath,
        public readonly string $mediaType,
        public readonly string $properties,
        public string $content,
        public readonly bool $obfuscated,
        public readonly string $url = '',
        public readonly ?string $fallback = null
    ) {
    }
}
