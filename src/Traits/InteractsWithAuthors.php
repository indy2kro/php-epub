<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use PhpEpub\Contributor;
use SimpleXMLElement;

trait InteractsWithAuthors
{
    /**
     * Gets the authors of the EPUB, in document order: the dc:creator elements with the
     * role "aut" or without a role (other creators, e.g. illustrators, are not authors).
     *
     * @return array<int, string>
     */
    public function getAuthors(): array
    {
        return array_map(static fn (SimpleXMLElement $element): string => (string) $element, $this->authorElements());
    }

    /**
     * Sets the authors of the EPUB. Creators with another role (e.g. illustrators) are kept.
     *
     * Existing authors are reused in order, so their roles (EPUB 2 opf:role, EPUB 3
     * role refinement) are kept. An author whose name changes loses its sort key
     * (opf:file-as / file-as refinement); removed authors lose all refinements.
     *
     * @param array<int, string> $authors
     */
    public function setAuthors(array $authors): void
    {
        $this->setDcValues('creator', array_values($authors), $this->authorElements(), ['file-as']);
    }

    /**
     * Gets every creator (dc:creator) with its role and sort key, in document order.
     *
     * @return list<Contributor>
     */
    public function getCreators(): array
    {
        return array_map($this->toContributor(...), $this->dcElements('creator'));
    }

    /**
     * Adds a creator, storing the role and sort key the way the package version expects
     * (EPUB 3 refinements, EPUB 2 opf:role / opf:file-as attributes).
     *
     * @param string|null $role A MARC relator code, e.g. "aut" (author) or "ill" (illustrator).
     */
    public function addCreator(string $name, ?string $role = 'aut', ?string $fileAs = null): void
    {
        $this->addPerson('creator', $name, $role, $fileAs);
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function authorElements(): array
    {
        return array_values(array_filter(
            $this->dcElements('creator'),
            fn (SimpleXMLElement $element): bool => in_array($this->personRole($element), [null, 'aut'], true)
        ));
    }
}
