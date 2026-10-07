<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use PhpEpub\Contributor;

trait InteractsWithContributors
{
    /**
     * Gets every contributor (dc:contributor) with its role and sort key, in document order.
     *
     * @return list<Contributor>
     */
    public function getContributors(): array
    {
        return array_map($this->toContributor(...), $this->dcElements('contributor'));
    }

    /**
     * Replaces the contributors by these names or Contributor objects (the inverse of getContributors()).
     * Existing contributors are reused in order, so their ids and refinements survive. A plain name keeps
     * the role of the contributor it reuses and drops its sort key when the name changes; a Contributor
     * sets the role and sort key exactly (null removes them).
     *
     * @param array<int, string|Contributor> $contributors
     *
     * @throws \PhpEpub\Exception If a value is not valid XML text or a name is empty; nothing is changed then.
     */
    public function setContributors(array $contributors): void
    {
        $this->setPeople('contributor', array_values($contributors));
    }

    /**
     * Adds a contributor, storing the role and sort key the way the package version expects
     * (EPUB 3 refinements, EPUB 2 opf:role / opf:file-as attributes).
     *
     * @param string|null $role A MARC relator code, e.g. "edt" (editor) or "trl" (translator).
     */
    public function addContributor(string $name, ?string $role = null, ?string $fileAs = null): void
    {
        $this->addPerson('contributor', $name, $role, $fileAs);
    }
}
