<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * One <item> of the OPF manifest.
 */
final readonly class ManifestItem
{
    /**
     * @param string $id The item id, referenced by spine itemrefs.
     * @param string $href The href as written in the OPF (relative to the OPF file, URL-encoded).
     * @param string $path The file path relative to the book root, usable with ContentManager ("" for remote resources).
     * @param string $mediaType The item's media type.
     * @param string $properties Space-separated EPUB 3 properties (e.g. "nav", "cover-image"), or "".
     */
    public function __construct(
        public string $id,
        public string $href,
        public string $path,
        public string $mediaType,
        public string $properties = ''
    ) {
    }
}
