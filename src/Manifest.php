<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\PathResolver;
use PhpEpub\Util\XmlText;
use SimpleXMLElement;

/**
 * Reads and edits the OPF manifest: the list of every resource in the book.
 */
class Manifest
{
    private const array MEDIA_TYPES = [
        'xhtml' => 'application/xhtml+xml',
        'html' => 'application/xhtml+xml',
        'htm' => 'application/xhtml+xml',
        'css' => 'text/css',
        // EPUB 3.3 lists text/javascript as the core media type for scripts.
        'js' => 'text/javascript',
        'ncx' => 'application/x-dtbncx+xml',
        'smil' => 'application/smil+xml',
        'pls' => 'application/pls+xml',
        'xml' => 'application/xml',
        'json' => 'application/json',
        'txt' => 'text/plain',
        'vtt' => 'text/vtt',
        'svg' => 'image/svg+xml',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'otf' => 'font/otf',
        'ttf' => 'font/ttf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/opus',
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    private readonly SimpleXMLElement $manifestNode;

    /**
     * Directory of the OPF file relative to the book root ("" at the root).
     */
    private readonly string $opfDirectory;

    private readonly string $opfPath;

    private bool $modified = false;

    /**
     * The items in document order, built on first use so lookups stay fast in large books;
     * add() extends it and every other change drops it (see forgetItems()).
     *
     * @var list<ManifestItem>|null
     */
    private ?array $items = null;

    /**
     * @var array<string, ManifestItem> The first item of each path.
     */
    private array $itemsByPath = [];

    /**
     * @var array<string, ManifestItem> The first item of each id.
     */
    private array $itemsById = [];

    /**
     * @var array<string, SimpleXMLElement> The <item> of each id in $itemsById.
     */
    private array $nodesById = [];

    /**
     * @param SimpleXMLElement $opfXml The parsed OPF package document.
     * @param string $opfPath The OPF path relative to the book root (as returned by Parser::parse()).
     *
     * @throws InvalidEpubException If the package has no manifest element.
     */
    public function __construct(
        private readonly SimpleXMLElement $opfXml,
        string $opfPath,
        private readonly PathResolver $paths = new PathResolver()
    ) {
        $manifestNodes = $this->query('/opf:package/opf:manifest');
        if ($manifestNodes === []) {
            throw new InvalidEpubException('Missing manifest in OPF file');
        }

        $this->manifestNode = $manifestNodes[0];
        $this->opfPath = $this->paths->normalize($opfPath);
        $directory = dirname($this->opfPath);
        $this->opfDirectory = $directory === '.' ? '' : $directory;
    }

    /**
     * @return list<ManifestItem>
     */
    public function getItems(): array
    {
        return $this->items();
    }

    public function get(string $id): ?ManifestItem
    {
        $this->items();

        return $this->itemsById[$id] ?? null;
    }

    /**
     * Finds the item for a file path relative to the book root.
     */
    public function findByPath(string $path): ?ManifestItem
    {
        $path = $this->paths->normalize($path);
        $this->items();

        return $this->itemsByPath[$path] ?? null;
    }

    /**
     * Adds a file (path relative to the book root) to the manifest.
     *
     * @param string|null $mediaType Defaults to a guess from the file extension.
     * @param string|null $id Defaults to an id derived from the file name.
     *
     * @throws Exception If the path is already listed or the id is taken.
     */
    public function add(string $path, ?string $mediaType = null, ?string $id = null): ManifestItem
    {
        $path = $this->paths->normalize($path);
        XmlText::assertValid($mediaType ?? '', $id ?? '');

        if ($this->findByPath($path) instanceof ManifestItem) {
            throw new Exception("File is already in the manifest: {$path}");
        }

        if ($id !== null && $this->idInUse($id)) {
            throw new Exception("Manifest id is already in use: {$id}");
        }

        $item = $this->manifestNode->addChild('item', null, Metadata::OPF_NAMESPACE);
        $item->addAttribute('id', $id ?? $this->uniqueId($path));
        $item->addAttribute('href', $this->pathToHref($path));
        $item->addAttribute('media-type', $mediaType ?? $this->guessMediaType($path));
        $this->modified = true;

        // A new item goes last, so the index only needs extending.
        return $this->items === null ? $this->toItem($item) : $this->indexItem($item);
    }

    /**
     * Removes an item from the manifest, together with the package references to it:
     * the EPUB 2 cover meta, refinements, spine@toc, fallback / media-overlay
     * attributes of other items and <guide> references to its file.
     *
     * Spine itemrefs are not touched; see Spine::remove().
     *
     * @throws Exception If no item has this id.
     */
    public function remove(string $id): void
    {
        $node = $this->requireNode($id);
        $href = (string) $node['href'];

        unset($node[0]);
        $this->removeReferences($id, $href);
        $this->modified = true;
        $this->forgetItems();
    }

    /**
     * Points an item at another file (path relative to the book root), keeping its id, and updates
     * the <guide> references to its old file. The file itself is not moved; ContentManager::moveContent()
     * moves both.
     *
     * @throws Exception If no item has this id, or another item already has the path.
     */
    public function moveItem(string $id, string $path): void
    {
        $path = $this->paths->normalize($path);
        $node = $this->requireNode($id);
        $other = $this->findByPath($path);
        if ($other instanceof ManifestItem && $other->id !== $id) {
            throw new Exception("File is already in the manifest: {$path}");
        }

        $oldPath = $this->tryHrefToPath((string) $node['href']);
        $node['href'] = $this->pathToHref($path);

        foreach ($this->query('/opf:package/opf:guide/opf:reference') as $reference) {
            [, $fragment] = array_pad(explode('#', (string) $reference['href'], 2), 2, null);
            if ($oldPath !== null && $this->tryHrefToPath((string) $reference['href']) === $oldPath) {
                $reference['href'] = $this->pathToHref($path) . ($fragment === null ? '' : '#' . $fragment);
            }
        }

        $this->modified = true;
        $this->forgetItems();
    }

    /**
     * Gives every manifest item whose id is already used by an earlier item a new, unique id ("id-2", "id-3", ...);
     * the first item keeps the id, which is also the one spine itemrefs and other references resolve to.
     *
     * @return list<array{string, string, string}> [old id, new id, path] of each renamed item.
     */
    public function renameDuplicateIds(): array
    {
        $seen = [];
        $renamed = [];
        foreach ($this->itemNodes() as $node) {
            $id = (string) $node['id'];
            if (! isset($seen[$id])) {
                $seen[$id] = true;
                continue;
            }

            $base = $id === '' ? 'item' : $id;
            $suffix = 2;
            while ($this->idInUse("{$base}-{$suffix}")) {
                $suffix++;
            }

            $new = "{$base}-{$suffix}";
            $node['id'] = $new;
            $seen[$new] = true;
            $renamed[] = [$id, $new, $this->tryHrefToPath((string) $node['href']) ?? ''];
        }

        if ($renamed !== []) {
            $this->modified = true;
            $this->forgetItems();
        }

        return $renamed;
    }

    /**
     * Changes the media type of an item, e.g. after its file was replaced with another format.
     *
     * @throws Exception If no item has this id, or the media type is not valid XML text.
     */
    public function setMediaType(string $id, string $mediaType): void
    {
        XmlText::assertValid($mediaType);
        $node = $this->requireNode($id);

        if ((string) $node['media-type'] !== $mediaType) {
            $node['media-type'] = $mediaType;
            $this->modified = true;
            $this->forgetItems();
        }
    }

    /**
     * Finds the item for an href relative to the OPF file (fragments are ignored);
     * null when no item matches or the href points outside the book.
     */
    public function findByHref(string $href): ?ManifestItem
    {
        $path = $this->tryHrefToPath($href);

        return $path === null ? null : $this->findByPath($path);
    }

    /**
     * The file path (relative to the book root) of the EPUB 2 <guide> reference of the
     * given type, e.g. "cover" or "toc"; null when there is none or it points outside the book.
     */
    public function getGuidePath(string $type): ?string
    {
        foreach ($this->query('/opf:package/opf:guide/opf:reference') as $reference) {
            if (strcasecmp((string) $reference['type'], $type) === 0) {
                return $this->tryHrefToPath((string) $reference['href']);
            }
        }

        return null;
    }

    /**
     * The EPUB 2 <guide> references as landmarks, with the guide's own types ("cover", "text",
     * "title-page", …; see Landmark::fromGuideType() for the EPUB 3 equivalents), in document order.
     * References that point outside the book are left out.
     *
     * @return list<Landmark>
     */
    public function getGuideReferences(): array
    {
        $references = [];
        foreach ($this->query('/opf:package/opf:guide/opf:reference') as $reference) {
            $href = (string) $reference['href'];
            $path = $this->tryHrefToPath($href);
            if ($path === null) {
                continue;
            }

            $fragment = explode('#', $href, 2)[1] ?? '';
            $references[] = new Landmark((string) $reference['type'], (string) $reference['title'], $path, $fragment === '' ? null : rawurldecode($fragment));
        }

        return $references;
    }

    /**
     * Replaces the EPUB 2 <guide> by these references (types are the guide's own); [] removes the guide.
     * The guide is created after the spine when the package has none.
     *
     * @param list<Landmark> $references
     *
     * @throws Exception If a value is not valid XML text or a path leaves the book.
     */
    public function setGuideReferences(array $references): void
    {
        foreach ($references as $reference) {
            XmlText::assertValid($reference->type, $reference->title, $reference->fragment ?? '');
            $this->paths->normalize($reference->path);
        }

        foreach ($this->query('/opf:package/opf:guide') as $guide) {
            unset($guide[0]);
        }

        if ($references !== []) {
            $guide = $this->opfXml->addChild('guide', null, Metadata::OPF_NAMESPACE);
            foreach ($references as $reference) {
                $node = $guide->addChild('reference', null, Metadata::OPF_NAMESPACE);
                $node->addAttribute('type', $reference->type);
                if ($reference->title !== '') {
                    $node->addAttribute('title', $reference->title);
                }

                $fragment = $reference->fragment === null || $reference->fragment === '' ? '' : '#' . rawurlencode($reference->fragment);
                $node->addAttribute('href', $this->pathToHref($reference->path) . $fragment);
            }

            $this->moveAfterSpine($guide);
        }

        $this->modified = true;
    }

    /**
     * The guide follows the spine (and precedes bindings and collections) in an EPUB 3 package.
     */
    private function moveAfterSpine(SimpleXMLElement $guide): void
    {
        $spine = $this->query('/opf:package/opf:spine')[0] ?? null;
        if (! $spine instanceof SimpleXMLElement) {
            return;
        }

        $spineNode = dom_import_simplexml($spine);
        $spineNode->parentNode?->insertBefore(dom_import_simplexml($guide), $spineNode->nextSibling);
    }

    /**
     * Whether the package is EPUB 3 (version 3.x), whose items carry properties.
     */
    public function isEpub3(): bool
    {
        return str_starts_with(trim((string) $this->opfXml['version']), '3');
    }

    /**
     * Removes the EPUB 2 <guide> references of a type (e.g. "cover"), compared case-insensitively,
     * and the guide when none is left.
     */
    public function removeGuideReferences(string $type): void
    {
        $this->removeGuideReferencesWhere(static fn (SimpleXMLElement $reference): bool => strcasecmp((string) $reference['type'], $type) === 0);
    }

    /**
     * Adds an EPUB 3 property token (e.g. "cover-image", "nav") to an item.
     *
     * @throws Exception If no item has this id.
     */
    public function addProperty(string $id, string $property): void
    {
        XmlText::assertValid($property);

        $node = $this->requireNode($id);
        $tokens = $this->propertyTokens($node);
        if (! in_array($property, $tokens, true)) {
            $tokens[] = $property;
            $this->writeProperties($node, $tokens);
        }
    }

    /**
     * Removes an EPUB 3 property token from an item.
     *
     * @throws Exception If no item has this id.
     */
    public function removeProperty(string $id, string $property): void
    {
        $node = $this->requireNode($id);
        $tokens = $this->propertyTokens($node);
        if (in_array($property, $tokens, true)) {
            $this->writeProperties($node, array_values(array_diff($tokens, [$property])));
        }
    }

    /**
     * The id of the media overlay (SMIL) item that narrates a content document, from its
     * media-overlay attribute; null when it has none or no item has this id.
     */
    public function getMediaOverlay(string $id): ?string
    {
        $overlay = (string) ($this->findNode($id)['media-overlay'] ?? '');

        return $overlay === '' ? null : $overlay;
    }

    /**
     * Sets the media overlay (SMIL document) that narrates an XHTML or SVG content document, or
     * removes it with null. Media overlays exist only in EPUB 3; give the overlay its total duration
     * with Metadata::setMediaDurationOf().
     *
     * @throws Exception If an item is unknown, the content item is not XHTML or SVG, the overlay is not an
     *                   application/smil+xml item, or the package is not EPUB 3.
     */
    public function setMediaOverlay(string $id, ?string $overlayId): void
    {
        $this->isEpub3() || throw new Exception('Media overlays exist only in EPUB 3 packages');

        $node = $this->requireNode($id);
        $mediaType = (string) $node['media-type'];
        if (! in_array($mediaType, ['application/xhtml+xml', 'image/svg+xml'], true)) {
            throw new Exception("Only XHTML and SVG content documents have a media overlay, but \"{$id}\" is {$mediaType}");
        }

        if ($overlayId !== null) {
            $overlayType = (string) $this->requireNode($overlayId)['media-type'];
            $overlayType === 'application/smil+xml' || throw new Exception("The media overlay \"{$overlayId}\" must be application/smil+xml, got: {$overlayType}");
        }

        unset($node['media-overlay']);
        if ($overlayId !== null) {
            $node->addAttribute('media-overlay', $overlayId);
        }

        $this->modified = true;
    }

    /**
     * Converts a path relative to the book root into an href relative to the OPF file.
     */
    public function pathToHref(string $path): string
    {
        return $this->paths->relativeHref($this->opfDirectory, $path);
    }

    /**
     * Converts an href relative to the OPF file into a path relative to the book root.
     *
     * @throws InvalidEpubException If the href points outside the book.
     */
    public function hrefToPath(string $href): string
    {
        $href = rawurldecode(explode('#', $href, 2)[0]);

        return $this->paths->normalize(($this->opfDirectory === '' ? '' : $this->opfDirectory . '/') . $href);
    }

    /**
     * The OPF path relative to the book root.
     */
    public function getOpfPath(): string
    {
        return $this->opfPath;
    }

    public function isModified(): bool
    {
        return $this->modified;
    }

    /**
     * Marks the current state as persisted.
     *
     * @internal Called by EpubFile::save() after writing the package; calling it before then makes
     *           save() treat the manifest as unchanged.
     */
    public function markSaved(): void
    {
        $this->modified = false;
    }

    /**
     * Clears every reference that would dangle once the item with this id and href is gone.
     */
    private function removeReferences(string $id, string $href): void
    {
        foreach ($this->query('/opf:package/opf:spine') as $spine) {
            if ((string) $spine['toc'] === $id) {
                unset($spine['toc']);
            }
        }

        foreach ($this->itemNodes() as $item) {
            foreach (['fallback', 'media-overlay'] as $attribute) {
                if ((string) $item[$attribute] === $id) {
                    unset($item[$attribute]);
                }
            }
        }

        foreach ($this->query('/opf:package/opf:metadata//opf:meta') as $meta) {
            $isCover = (string) $meta['name'] === 'cover' && (string) $meta['content'] === $id;
            if ($isCover || (string) $meta['refines'] === '#' . $id) {
                unset($meta[0]);
            }
        }

        $path = $this->tryHrefToPath($href);
        if ($path === null) {
            return;
        }

        $this->removeGuideReferencesWhere(fn (SimpleXMLElement $reference): bool => $this->tryHrefToPath((string) $reference['href']) === $path);
    }

    /**
     * Removes the matching <guide> references, and the guide when none is left (OPF 2 requires
     * at least one reference in a guide).
     *
     * @param \Closure(SimpleXMLElement): bool $matches
     */
    private function removeGuideReferencesWhere(\Closure $matches): void
    {
        foreach ($this->query('/opf:package/opf:guide') as $guide) {
            foreach ($this->query('/opf:package/opf:guide/opf:reference') as $reference) {
                if ($matches($reference)) {
                    unset($reference[0]);
                    $this->modified = true;
                }
            }

            if ($guide->children(Metadata::OPF_NAMESPACE)->count() === 0) {
                unset($guide[0]);
            }
        }
    }

    private function tryHrefToPath(string $href): ?string
    {
        try {
            return $this->hrefToPath($href);
        } catch (InvalidEpubException) {
            return null;
        }
    }

    private function toItem(SimpleXMLElement $node): ManifestItem
    {
        $href = (string) $node['href'];
        // EPUB 3 allows remote resources (e.g. streamed audio); they have no file in the book.
        // Neither has an href that escapes the book: one hostile item must not hide the others.
        $isRemote = preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1;

        return new ManifestItem(
            (string) $node['id'],
            $href,
            $isRemote ? '' : ($this->tryHrefToPath($href) ?? ''),
            (string) $node['media-type'],
            (string) $node['properties']
        );
    }

    /**
     * @throws Exception If no item has this id.
     */
    private function requireNode(string $id): SimpleXMLElement
    {
        $node = $this->findNode($id);
        if (! $node instanceof SimpleXMLElement) {
            throw new Exception("No manifest item with id \"{$id}\"");
        }

        return $node;
    }

    /**
     * @return list<string>
     */
    private function propertyTokens(SimpleXMLElement $node): array
    {
        return preg_split('/\s+/', trim((string) $node['properties']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param list<string> $tokens
     */
    private function writeProperties(SimpleXMLElement $node, array $tokens): void
    {
        if ($tokens === []) {
            unset($node['properties']);
        } else {
            $node['properties'] = implode(' ', $tokens);
        }

        $this->modified = true;
        $this->forgetItems();
    }

    private function findNode(string $id): ?SimpleXMLElement
    {
        $this->items();

        return $this->nodesById[$id] ?? null;
    }

    /**
     * @return list<ManifestItem>
     */
    private function items(): array
    {
        if ($this->items === null) {
            $this->items = [];
            $this->itemsByPath = [];
            $this->itemsById = [];
            $this->nodesById = [];
            foreach ($this->itemNodes() as $node) {
                $this->indexItem($node);
            }
        }

        return $this->items;
    }

    /**
     * Appends an <item> to the index; with duplicate paths or ids the first item wins, as in the document.
     */
    private function indexItem(SimpleXMLElement $node): ManifestItem
    {
        $item = $this->toItem($node);
        $this->items[] = $item;
        if ($item->path !== '') {
            $this->itemsByPath[$item->path] ??= $item;
        }

        if (! isset($this->itemsById[$item->id])) {
            $this->itemsById[$item->id] = $item;
            $this->nodesById[$item->id] = $node;
        }

        return $item;
    }

    /**
     * Drops the index after a change to existing items; the next lookup rebuilds it.
     */
    private function forgetItems(): void
    {
        $this->items = null;
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function itemNodes(): array
    {
        return $this->query('/opf:package/opf:manifest/opf:item');
    }

    private function uniqueId(string $path): string
    {
        // Ids must be XML names: letters first, then letters, digits, "-", "_" or ".".
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($path))), '-');
        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = 'item-' . $base;
        }

        $id = $base;
        for ($suffix = 2; $this->idInUse($id); $suffix++) {
            $id = "{$base}-{$suffix}";
        }

        return $id;
    }

    /**
     * XML ids must be unique in the whole package document, not only among manifest items.
     */
    private function idInUse(string $id): bool
    {
        foreach ($this->opfXml->xpath('//@id') ?: [] as $attribute) {
            if ((string) $attribute === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The media type for a file name's extension; "application/octet-stream" when it is not known.
     */
    public function guessMediaType(string $path): string
    {
        return self::MEDIA_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
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
