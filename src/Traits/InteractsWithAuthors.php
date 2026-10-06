<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithAuthors
{
    /**
     * Gets the authors (dc:creator) of the EPUB, in document order.
     *
     * @return array<int, string>
     */
    public function getAuthors(): array
    {
        return $this->getDcValues('creator');
    }

    /**
     * Sets the authors (dc:creator) of the EPUB.
     *
     * Existing creators are reused in order, so roles (EPUB 2 opf:role, EPUB 3
     * role refinements) are kept. A creator whose name changes loses its sort key
     * (opf:file-as / file-as refinement); removed creators lose all refinements.
     *
     * @param array<int, string> $authors
     */
    public function setAuthors(array $authors): void
    {
        $this->setDcValues('creator', array_values($authors), null, ['file-as']);
    }
}
