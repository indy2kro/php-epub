<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * A dc:identifier with its scheme, as returned by Metadata::getTypedIdentifiers().
 */
final readonly class Identifier
{
    /**
     * @param string $value The identifier as written in the book (trimmed).
     * @param string|null $scheme The scheme in upper case, e.g. "ISBN", "UUID", "DOI" or a book's own
     *                            scheme name; null when the book does not say and the value does not show it.
     */
    public function __construct(
        public string $value,
        public ?string $scheme = null
    ) {
    }
}
