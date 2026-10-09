<?php

declare(strict_types=1);

namespace PhpEpub\Repair;

use Stringable;

/**
 * One change Repairer made to a book.
 */
final readonly class AppliedFix implements Stringable
{
    /**
     * @param string $code The ValidationIssue code the change resolves, e.g. "MANIFEST_FILE_MISSING";
     *                     "COVER_NOT_DECLARED", "COVER_NOT_FLAGGED" and "UPGRADED_TO_EPUB3" have no issue of their own.
     * @param string $description What was changed.
     * @param string|null $location The file (relative to the book root) or package value concerned, if any.
     */
    public function __construct(
        public string $code,
        public string $description,
        public ?string $location = null
    ) {
    }

    public function __toString(): string
    {
        return $this->code . ($this->location === null ? '' : " ({$this->location})") . ': ' . $this->description;
    }
}
