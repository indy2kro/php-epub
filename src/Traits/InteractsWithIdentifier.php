<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use PhpEpub\Exception;
use PhpEpub\Identifier;
use PhpEpub\Metadata;
use SimpleXMLElement;

trait InteractsWithIdentifier
{
    /**
     * ONIX code list 5 identifier types (EPUB 3 identifier-type refinements) with a common name.
     */
    private const array ONIX_IDENTIFIER_TYPES = ['02' => 'ISBN', '15' => 'ISBN', '06' => 'DOI', '22' => 'URN'];
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

    /**
     * Makes package@unique-identifier name a dc:identifier that has a value: a package without a usable one gets
     * the first non-empty dc:identifier (given an id when it has none), else the first dc:identifier, else a
     * new one, holding $fallback.
     *
     * @return bool True when the package was changed; false when its unique identifier was already usable.
     *
     * @throws Exception If $fallback is empty or not valid XML text.
     */
    public function ensureUniqueIdentifier(string $fallback): bool
    {
        $unique = $this->uniqueIdentifierElement();
        if ($unique instanceof SimpleXMLElement && trim((string) $unique) !== '') {
            return false;
        }

        $this->assertDcValues('identifier', [$fallback]);
        $elements = $this->dcElements('identifier');
        $chosen = null;
        foreach ($elements as $element) {
            if (trim((string) $element) !== '') {
                $chosen = $element;
                break;
            }
        }

        $chosen ??= $elements[0] ?? $this->addDcElement('identifier', $fallback);
        if (trim((string) $chosen) === '') {
            $this->setText($chosen, $fallback);
        }

        $id = (string) $chosen['id'];
        if ($id === '') {
            $id = $this->unusedId('pub-id');
            $chosen->addAttribute('id', $id);
        }

        $this->opfXml['unique-identifier'] = $id;
        $this->modified = true;

        return true;
    }

    /**
     * Gets the identifiers with their schemes, in document order. The scheme comes from the EPUB 2
     * opf:scheme attribute, else the EPUB 3 identifier-type refinement (ONIX codes 02 and 15 are
     * "ISBN", 06 "DOI"), else a "urn:<scheme>:" or "doi:" prefix, else an ISBN check digit.
     *
     * @return list<Identifier>
     */
    public function getTypedIdentifiers(): array
    {
        return array_map(
            fn (SimpleXMLElement $element): Identifier => new Identifier(trim((string) $element), $this->identifierScheme($element)),
            $this->dcElements('identifier')
        );
    }

    /**
     * Gets the first ISBN (see getTypedIdentifiers()) without prefix, hyphens or spaces, e.g.
     * "9780306406157"; null when the book has none.
     */
    public function getIsbn(): ?string
    {
        foreach ($this->getTypedIdentifiers() as $identifier) {
            if ($identifier->scheme === 'ISBN') {
                return (string) preg_replace(['/^(?:urn:)?isbn:/i', '/[\s-]+/'], '', $identifier->value);
            }
        }

        return null;
    }

    private function identifierScheme(SimpleXMLElement $element): ?string
    {
        $attributes = $element->attributes(Metadata::OPF_NAMESPACE);
        $declared = trim((string) ($attributes['scheme'] ?? ''));
        if ($declared !== '') {
            return strtoupper($declared);
        }

        $type = trim((string) $this->refinementValue($element, 'identifier-type'));
        if ($type !== '') {
            return self::ONIX_IDENTIFIER_TYPES[$type] ?? strtoupper($type);
        }

        $value = trim((string) $element);
        if (preg_match('/^(?:urn:([a-z0-9-]+)|(doi)):/i', $value, $match) === 1) {
            return strtoupper($match[1] !== '' ? $match[1] : $match[2]);
        }

        return self::isIsbn($value) ? 'ISBN' : null;
    }

    /**
     * Whether the value is an ISBN-10 or ISBN-13 (hyphens and spaces allowed) with a valid check digit.
     */
    private static function isIsbn(string $value): bool
    {
        $isbn = strtoupper((string) preg_replace('/[\s-]+/', '', $value));

        if (preg_match('/^\d{13}$/', $isbn) === 1) {
            $sum = 0;
            foreach (str_split($isbn) as $position => $digit) {
                $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
            }

            return $sum % 10 === 0;
        }

        if (preg_match('/^\d{9}[\dX]$/', $isbn) === 1) {
            $sum = 0;
            foreach (str_split($isbn) as $position => $digit) {
                $sum += ($digit === 'X' ? 10 : (int) $digit) * (10 - $position);
            }

            return $sum % 11 === 0;
        }

        return false;
    }
}
