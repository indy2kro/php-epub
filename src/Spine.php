<?php

declare(strict_types=1);

namespace PhpEpub;

use SimpleXMLElement;

/**
 * Reads and edits the OPF spine: the reading order of the book.
 */
class Spine
{
    /**
     * @var array<int, string>
     */
    protected array $spine = [];

    private bool $modified = false;

    /**
     * Spine constructor.
     *
     * @param Manifest|null $manifest Needed to resolve items (getItems()) and to validate add().
     */
    public function __construct(
        private readonly SimpleXMLElement $opfXml,
        private readonly ?Manifest $manifest = null
    ) {
        $this->reload();
    }

    /**
     * Returns the idrefs in reading order.
     *
     * @return array<int, string>
     */
    public function get(): array
    {
        return $this->spine;
    }

    /**
     * Returns the spine entries in reading order, with the linear flag and the manifest item.
     *
     * @return list<SpineItem>
     */
    public function getItems(): array
    {
        return array_map(
            fn (SimpleXMLElement $itemref): SpineItem => new SpineItem(
                (string) $itemref['idref'],
                (string) $itemref['linear'] !== 'no',
                $this->manifest?->get((string) $itemref['idref'])
            ),
            $this->itemrefNodes()
        );
    }

    public function contains(string $idref): bool
    {
        return in_array($idref, $this->spine, true);
    }

    /**
     * Adds a manifest item to the reading order.
     *
     * @param int|null $position Zero-based position, from 0 to the spine length; appends when null.
     * @param bool $linear False for auxiliary content (written as linear="no").
     *
     * @throws Exception If the item is unknown or already in the spine, or the position is out of range.
     */
    public function add(string $idref, ?int $position = null, bool $linear = true): void
    {
        if ($this->manifest instanceof Manifest && ! $this->manifest->get($idref) instanceof ManifestItem) {
            throw new Exception("Item \"{$idref}\" is not in the manifest");
        }

        if ($this->contains($idref)) {
            throw new Exception("Item \"{$idref}\" is already in the spine");
        }

        $entries = $this->entries();
        $position ??= count($entries);
        $this->assertPosition($position, count($entries));

        $entry = $linear ? ['idref' => $idref] : ['idref' => $idref, 'linear' => 'no'];
        array_splice($entries, $position, 0, [$entry]);

        $this->write($entries);
    }

    /**
     * Removes an item from the reading order (the manifest is not touched).
     *
     * @throws Exception If the item is not in the spine.
     */
    public function remove(string $idref): void
    {
        $entries = $this->entries();
        $index = $this->indexOf($idref);

        array_splice($entries, $index, 1);

        $this->write($entries);
    }

    /**
     * Moves an item to a new zero-based position in the reading order (0 to the spine length - 1).
     *
     * @throws Exception If the item is not in the spine or the position is out of range.
     */
    public function move(string $idref, int $position): void
    {
        $entries = $this->entries();
        $index = $this->indexOf($idref);
        $this->assertPosition($position, count($entries) - 1);

        $entry = array_splice($entries, $index, 1);
        array_splice($entries, $position, 0, $entry);

        $this->write($entries);
    }

    /**
     * Marks an entry as part of the main reading order (linear) or as auxiliary content (linear="no").
     * Its other attributes are kept.
     *
     * @throws Exception If the item is not in the spine.
     */
    public function setLinear(string $idref, bool $linear): void
    {
        $entries = $this->entries();
        $index = $this->indexOf($idref);

        unset($entries[$index]['linear']);
        if (! $linear) {
            $entries[$index]['linear'] = 'no';
        }

        $this->write($entries);
    }

    /**
     * The EPUB 3 page-progression-direction of the book ("ltr", "rtl" or "default"), or null when not set.
     */
    public function getPageProgressionDirection(): ?string
    {
        $direction = (string) ($this->spineNode()['page-progression-direction'] ?? '');

        return $direction === '' ? null : $direction;
    }

    /**
     * Sets the EPUB 3 page-progression-direction, e.g. "rtl" for right-to-left books such as manga;
     * null removes it.
     *
     * @throws Exception If the value is not "ltr", "rtl" or "default", or the package is not EPUB 3.
     */
    public function setPageProgressionDirection(?string $direction): void
    {
        if ($direction !== null && ! in_array($direction, ['ltr', 'rtl', 'default'], true)) {
            throw new Exception("page-progression-direction must be \"ltr\", \"rtl\" or \"default\", got: {$direction}");
        }

        if (! str_starts_with(trim((string) $this->opfXml['version']), '3')) {
            throw new Exception('page-progression-direction exists only in EPUB 3 packages');
        }

        $spineNode = $this->spineNode();
        unset($spineNode['page-progression-direction']);
        if ($direction !== null) {
            $spineNode->addAttribute('page-progression-direction', $direction);
        }

        $this->modified = true;
    }

    public function isModified(): bool
    {
        return $this->modified;
    }

    /**
     * Marks the current state as persisted.
     *
     * @internal Called by EpubFile::save() after writing the package; calling it before then makes
     *           save() treat the reading order as unchanged.
     */
    public function markSaved(): void
    {
        $this->modified = false;
    }

    /**
     * @throws Exception
     */
    private function indexOf(string $idref): int
    {
        $index = array_search($idref, $this->spine, true);
        if ($index === false) {
            throw new Exception("Item \"{$idref}\" is not in the spine");
        }

        return $index;
    }

    /**
     * @throws Exception
     */
    private function assertPosition(int $position, int $last): void
    {
        if ($position < 0 || $position > $last) {
            throw new Exception("Position {$position} is outside the spine (0 to {$last})");
        }
    }

    /**
     * The <spine> element, created when the package has none.
     */
    private function spineNode(): SimpleXMLElement
    {
        return $this->query('/opf:package/opf:spine')[0] ?? $this->opfXml->addChild('spine', null, Metadata::OPF_NAMESPACE);
    }

    /**
     * Current itemrefs as attribute maps, so id, linear and properties survive a rewrite.
     *
     * @return list<array<string, string>>
     */
    private function entries(): array
    {
        return array_map(
            static function (SimpleXMLElement $itemref): array {
                $attributes = [];
                foreach ($itemref->attributes() ?? [] as $name => $value) {
                    $attributes[(string) $name] = (string) $value;
                }

                return $attributes;
            },
            $this->itemrefNodes()
        );
    }

    /**
     * Rewrites all itemrefs in the given order.
     *
     * @param list<array<string, string>> $entries
     */
    private function write(array $entries): void
    {
        $spineNode = $this->spineNode();

        foreach ($this->itemrefNodes() as $itemref) {
            unset($itemref[0]);
        }

        foreach ($entries as $entry) {
            $itemref = $spineNode->addChild('itemref', null, Metadata::OPF_NAMESPACE);
            foreach ($entry as $name => $value) {
                $itemref->addAttribute($name, $value);
            }
        }

        $this->modified = true;
        $this->reload();
    }

    private function reload(): void
    {
        $this->spine = array_map(static fn (SimpleXMLElement $itemref): string => (string) $itemref['idref'], $this->itemrefNodes());
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function itemrefNodes(): array
    {
        // Namespace-aware, so prefixed packages (<opf:package>) work as well.
        return $this->query('/opf:package/opf:spine/opf:itemref');
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function query(string $expression): array
    {
        $this->opfXml->registerXPathNamespace('opf', Metadata::OPF_NAMESPACE);
        $result = $this->opfXml->xpath($expression);

        return $result === false || $result === null ? [] : array_values($result);
    }
}
