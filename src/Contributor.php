<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * A person or organisation credited in the metadata (dc:creator or dc:contributor).
 */
final readonly class Contributor
{
    /**
     * @param string $name The name as displayed.
     * @param string|null $role A MARC relator code such as "aut", "ill", "edt" or "trl"; null when not given.
     * @param string|null $fileAs The sort key, e.g. "Doe, Jane"; null when not given.
     */
    public function __construct(
        public string $name,
        public ?string $role,
        public ?string $fileAs
    ) {
    }
}
