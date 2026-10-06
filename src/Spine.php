<?php

declare(strict_types=1);

namespace PhpEpub;

use SimpleXMLElement;

class Spine
{
    /**
     * @var array<int, string>
     */
    protected array $spine = [];

    /**
     * Spine constructor.
     */
    public function __construct(private readonly SimpleXMLElement $opfXml)
    {
        // Namespace-aware, so prefixed packages (<opf:package>) work as well.
        $this->opfXml->registerXPathNamespace('opf', Metadata::OPF_NAMESPACE);
        $itemrefs = $this->opfXml->xpath('/opf:package/opf:spine/opf:itemref') ?: [];

        foreach ($itemrefs as $item) {
            $this->spine[] = (string) $item['idref'];
        }
    }

    /**
     * @return array<int, string>
     */
    public function get(): array
    {
        return $this->spine;
    }
}
