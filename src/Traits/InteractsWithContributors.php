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
     * Replaces the contributors by these names. Existing contributors are reused in order,
     * so their roles are kept; one whose name changes loses its sort key.
     *
     * @param array<int, string> $names
     */
    public function setContributors(array $names): void
    {
        $this->setDcValues('contributor', array_values($names), null, ['file-as']);
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
