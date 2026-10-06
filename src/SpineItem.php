<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * One <itemref> of the OPF spine (reading order).
 */
final readonly class SpineItem
{
    /**
     * @param string $idref The referenced manifest item id.
     * @param bool $linear False for auxiliary content (linear="no"), e.g. notes.
     * @param ManifestItem|null $item The referenced manifest item, when the spine knows the manifest.
     */
    public function __construct(
        public string $idref,
        public bool $linear = true,
        public ?ManifestItem $item = null
    ) {
    }
}
