<?php

declare(strict_types=1);

namespace PhpEpub\Repair;

use DateTimeInterface;
use PhpEpub\Exception;
use PhpEpub\Util\MetadataSyntax;

/**
 * Which repairs Repairer applies, and the values it uses where the book has none.
 */
final readonly class RepairOptions
{
    /**
     * @var list<RepairFix>
     */
    public array $fixes;

    /**
     * @param list<RepairFix>|null $fixes The repairs to apply; every one except RepairFix::UpgradeToEpub3 when null.
     * @param string $defaultLanguage The dc:language of a book without one: a well-formed BCP 47 tag.
     * @param DateTimeInterface|null $now The time written as a missing dcterms:modified; the current time when null
     *                                    (pass a fixed time in tests).
     *
     * @throws Exception If the default language is not a well-formed BCP 47 tag.
     */
    public function __construct(
        ?array $fixes = null,
        public string $defaultLanguage = 'en',
        public ?DateTimeInterface $now = null
    ) {
        if (! MetadataSyntax::isLanguageTag($defaultLanguage)) {
            throw new Exception("\"{$defaultLanguage}\" is not a well-formed BCP 47 language tag.");
        }

        $this->fixes = array_values(array_unique($fixes ?? RepairFix::defaults(), SORT_REGULAR));
    }

    /**
     * Only these repairs, nothing else.
     */
    public static function only(RepairFix ...$fixes): self
    {
        return new self(array_values($fixes));
    }

    /**
     * These repairs added to the ones already selected.
     */
    public function with(RepairFix ...$fixes): self
    {
        return new self([...$this->fixes, ...array_values($fixes)], $this->defaultLanguage, $this->now);
    }

    /**
     * The selected repairs without these.
     */
    public function without(RepairFix ...$fixes): self
    {
        return new self(
            array_values(array_filter($this->fixes, static fn (RepairFix $fix): bool => ! in_array($fix, $fixes, true))),
            $this->defaultLanguage,
            $this->now
        );
    }

    public function withDefaultLanguage(string $language): self
    {
        return new self($this->fixes, $language, $this->now);
    }

    public function withNow(?DateTimeInterface $now): self
    {
        return new self($this->fixes, $this->defaultLanguage, $now);
    }

    public function includes(RepairFix $fix): bool
    {
        return in_array($fix, $this->fixes, true);
    }
}
