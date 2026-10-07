<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use PhpEpub\Exception;
use SimpleXMLElement;

trait InteractsWithIdentifier
{
    /**
     * Gets the identifiers (dc:identifier) of the EPUB, in document order.
     *
     * @return array<int, string>
     */
    public function getIdentifiers(): array
    {
        return $this->getDcValues('identifier');
    }

    /**
     * Gets the unique identifier: the dc:identifier referenced by package@unique-identifier,
     * or null when it references none.
     */
    public function getUniqueIdentifier(): ?string
    {
        $unique = $this->uniqueIdentifierElement();

        return $unique instanceof SimpleXMLElement ? trim((string) $unique) : null;
    }

    /**
     * Sets the identifiers (dc:identifier) of the EPUB.
     *
     * The first value is stored in the identifier referenced by
     * package@unique-identifier, so the package stays valid. An identifier whose
     * value changes loses its type information (opf:scheme / identifier-type
     * refinement); removed identifiers lose all refinements.
     *
     * @param array<int, string> $identifiers
     *
     * @throws Exception If no identifier is given.
     */
    public function setIdentifiers(array $identifiers): void
    {
        if ($identifiers === []) {
            throw new Exception('At least one identifier is required');
        }

        $elements = $this->dcElements('identifier');
        $unique = $this->uniqueIdentifierElement();

        if ($unique instanceof SimpleXMLElement) {
            $uniqueId = (string) $unique['id'];
            $others = array_filter($elements, static fn (SimpleXMLElement $element): bool => (string) $element['id'] !== $uniqueId);
            $elements = [$unique, ...array_values($others)];
        }

        $this->setDcValues('identifier', array_values($identifiers), $elements, ['scheme', 'identifier-type']);
    }
}
