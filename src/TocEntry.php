<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * One entry of the table of contents.
 */
final readonly class TocEntry
{
    /**
     * @param string $title The text shown in the table of contents.
     * @param string $path The target file relative to the book root, e.g. "EPUB/text/ch1.xhtml";
     *                     "" for a heading without a link (or a link that leaves the book).
     * @param string|null $fragment The target anchor inside the file (without "#"), or null.
     * @param list<TocEntry> $children Nested entries.
     */
    public function __construct(
        public string $title,
        public string $path = '',
        public ?string $fragment = null,
        public array $children = []
    ) {
    }
}
