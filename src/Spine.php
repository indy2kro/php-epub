<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\Rendition;
use PhpEpub\Util\XmlText;
use SimpleXMLElement;

/**
 * Reads and edits the OPF spine: the reading order of the book.
 */
class Spine
{
    /**
     * The spine item properties that place a fixed-layout page in a spread, by side.
     */
    private const array SPREAD_PROPERTIES = [
        'left' => 'page-spread-left',
        'right' => 'page-spread-right',
        'center' => 'rendition:page-spread-center',
    ];


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
                $this->manifest?->get((string) $itemref['idref']),
                $this->tokens((string) $itemref['properties'])
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
     * Appends many manifest items to the reading order at once; add() and setItemProperties() rewrite the whole
     * spine each time, which is slow for thousands of items.
     *
     * @param list<array{idref: string, linear?: bool, properties?: list<string>}> $entries
     *
     * @throws Exception If an item is unknown or already in the spine (or listed twice), a property is invalid, or
     *                   properties are given for a package that is not EPUB 3. Nothing is added then.
     */
    public function addMany(array $entries): void
    {
        $all = $this->entries();
        $known = array_flip($this->spine);
        foreach ($entries as $entry) {
            $idref = $entry['idref'];
            if ($this->manifest instanceof Manifest && ! $this->manifest->get($idref) instanceof ManifestItem) {
                throw new Exception("Item \"{$idref}\" is not in the manifest");
            }

            isset($known[$idref]) && throw new Exception("Item \"{$idref}\" is already in the spine");
            $known[$idref] = true;

            $row = ['idref' => $idref];
            if (($entry['linear'] ?? true) === false) {
                $row['linear'] = 'no';
            }

            $properties = $entry['properties'] ?? [];
            if ($properties !== []) {
                $this->assertEpub3('Spine item properties');
                foreach ($properties as $property) {
                    XmlText::assertValid($property);
                    preg_match('/^\S+$/', $property) === 1 || throw new Exception('A spine item property must not be empty or contain white space');
                    if (str_starts_with($property, 'rendition:')) {
                        Rendition::declarePrefix($this->opfXml);
                    }
                }

                $row['properties'] = implode(' ', array_unique($properties));
            }

            $all[] = $row;
        }

        $this->write($all);
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
     * The id of the NCX manifest item the spine names (<spine toc="…">); null when it names none.
     */
    public function getToc(): ?string
    {
        $toc = (string) ($this->spineNode()['toc'] ?? '');

        return $toc === '' ? null : $toc;
    }

    /**
     * Points the spine at the NCX manifest item (EPUB 2 reading systems find the NCX this way); null removes it.
     *
     * @throws Exception If the item is not in the manifest.
     */
    public function setToc(?string $id): void
    {
        if ($id !== null && $this->manifest instanceof Manifest && ! $this->manifest->get($id) instanceof ManifestItem) {
            throw new Exception("Item \"{$id}\" is not in the manifest");
        }

        $spineNode = $this->spineNode();
        unset($spineNode['toc']);
        if ($id !== null) {
            $spineNode->addAttribute('toc', $id);
        }

        $this->modified = true;
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

    /**
     * The EPUB 3 properties of a spine entry (<itemref properties="…">), e.g. "page-spread-left" or
     * "rendition:layout-pre-paginated"; [] when it has none.
     *
     * @return list<string>
     *
     * @throws Exception If the item is not in the spine.
     */
    public function getItemProperties(string $idref): array
    {
        return $this->tokens($this->entries()[$this->indexOf($idref)]['properties'] ?? '');
    }

    /**
     * Replaces the properties of a spine entry; [] removes them. Writing a "rendition:" property declares
     * the rendition prefix in the package's prefix attribute. setPageSpread() and setItemRendition()
     * take care of the spread and rendition properties.
     *
     * @param list<string> $properties Property tokens, without white space.
     *
     * @throws Exception If the item is not in the spine, a property is empty, has white space or is not valid
     *                   XML text, or the package is not EPUB 3.
     */
    public function setItemProperties(string $idref, array $properties): void
    {
        $this->assertEpub3('Spine item properties');

        $entries = $this->entries();
        $index = $this->indexOf($idref);

        foreach ($properties as $property) {
            XmlText::assertValid($property);
            if (preg_match('/^\S+$/', $property) !== 1) {
                throw new Exception('A spine item property must not be empty or contain white space');
            }

            if (str_starts_with($property, 'rendition:')) {
                Rendition::declarePrefix($this->opfXml);
            }
        }

        unset($entries[$index]['properties']);
        if ($properties !== []) {
            $entries[$index]['properties'] = implode(' ', array_unique($properties));
        }

        $this->write($entries);
    }

    /**
     * Which side of a fixed-layout spread a page belongs on: "left" (page-spread-left), "right"
     * (page-spread-right) or "center" (rendition:page-spread-center); null when it is not set.
     *
     * @throws Exception If the item is not in the spine.
     */
    public function getPageSpread(string $idref): ?string
    {
        $tokens = $this->getItemProperties($idref);
        foreach (self::SPREAD_PROPERTIES as $side => $property) {
            if (in_array($property, $tokens, true)) {
                return $side;
            }
        }

        return null;
    }

    /**
     * Places a page on the left or right of a spread, or alone in the center; null removes the placement.
     * Other properties of the entry are kept.
     *
     * @param string|null $side "left", "right" or "center".
     *
     * @throws Exception If the item is not in the spine, the side is not allowed, or the package is not EPUB 3.
     */
    public function setPageSpread(string $idref, ?string $side): void
    {
        if ($side !== null && ! isset(self::SPREAD_PROPERTIES[$side])) {
            throw new Exception("The page spread must be \"left\", \"right\" or \"center\", got: {$side}");
        }

        $tokens = array_values(array_diff($this->getItemProperties($idref), self::SPREAD_PROPERTIES));
        if ($side !== null) {
            $tokens[] = self::SPREAD_PROPERTIES[$side];
        }

        $this->setItemProperties($idref, $tokens);
    }

    /**
     * The rendition override of a spine entry for an aspect ("layout", "orientation", "spread" or "flow"),
     * e.g. "pre-paginated" for the "rendition:layout-pre-paginated" property; null when it has none.
     *
     * @throws Exception If the item is not in the spine or the aspect is not allowed.
     */
    public function getItemRendition(string $idref, string $aspect): ?string
    {
        Rendition::assertAspect($aspect);
        $prefix = 'rendition:' . $aspect . '-';

        foreach ($this->getItemProperties($idref) as $property) {
            if (str_starts_with($property, $prefix)) {
                return substr($property, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * Overrides the book's rendition for one spine entry, e.g. a pre-paginated page in a reflowable book;
     * null removes the override. Other properties of the entry are kept, and the rendition prefix is
     * declared in the package.
     *
     * @param string $aspect "layout", "orientation", "spread" or "flow".
     * @param string|null $value A value allowed for the aspect (see Metadata::setRenditionLayout() and its siblings).
     *
     * @throws Exception If the item is not in the spine, the aspect or value is not allowed, or the package is not EPUB 3.
     */
    public function setItemRendition(string $idref, string $aspect, ?string $value): void
    {
        Rendition::assertAspect($aspect);
        if ($value !== null) {
            Rendition::assertValue($aspect, $value);
        }

        $prefix = 'rendition:' . $aspect . '-';
        $tokens = array_values(array_filter(
            $this->getItemProperties($idref),
            static fn (string $property): bool => ! str_starts_with($property, $prefix)
        ));
        if ($value !== null) {
            $tokens[] = $prefix . $value;
        }

        $this->setItemProperties($idref, $tokens);
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
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        return preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @throws Exception
     */
    private function assertEpub3(string $what): void
    {
        str_starts_with(trim((string) $this->opfXml['version']), '3') || throw new Exception("{$what} exist only in EPUB 3 packages");
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
