<?php

declare(strict_types=1);

namespace PhpEpub;

use Stringable;

/**
 * One problem found by EpubFile::validate().
 */
final readonly class ValidationIssue implements Stringable
{
    public const string ERROR = 'error';

    public const string WARNING = 'warning';

    /**
     * @param string $severity ValidationIssue::ERROR (readers or EPUBCheck reject it) or ValidationIssue::WARNING.
     * @param string $code A stable identifier, e.g. "MANIFEST_FILE_MISSING".
     * @param string $message A readable description.
     * @param string|null $location The file (relative to the book root) or package value concerned, if any.
     */
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public ?string $location = null
    ) {
    }

    public function __toString(): string
    {
        return $this->severity . ' ' . $this->code . ($this->location === null ? '' : " ({$this->location})") . ': ' . $this->message;
    }
}
