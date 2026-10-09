<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Kindle\KindleChecker;

/**
 * A set of extra checks for one target, passed to EpubFile::validate(); the issues it reports use the
 * same ValidationIssue class, with a "fix" hint.
 */
final readonly class ValidationProfile
{
    private const string KINDLE = 'kindle';

    private function __construct(public string $name)
    {
    }

    /**
     * Checks for sending the book to a Kindle (Send to Kindle) or publishing it with KDP; see KindleChecker.
     */
    public static function kindle(): self
    {
        return new self(self::KINDLE);
    }

    /**
     * @return list<ValidationIssue>
     *
     * @throws Exception If the book is not loaded.
     */
    public function check(EpubFile $epub): array
    {
        return match ($this->name) {
            self::KINDLE => (new KindleChecker($epub))->check(),
            default => [],
        };
    }
}
