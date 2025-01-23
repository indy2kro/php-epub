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
        foreach ($this->opfXml->spine->itemref as $item) {
            $attr = $item->attributes();
            $this->spine[] = (string) $attr->idref;
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
