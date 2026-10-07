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
        return array_map($this->toItem(...), $this->itemNodes());
    }

    public function get(string $id): ?ManifestItem
    {
        $node = $this->findNode($id);

        return $node instanceof SimpleXMLElement ? $this->toItem($node) : null;
    }

    /**
     * Finds the item for a file path relative to the book root.
     */
    public function findByPath(string $path): ?ManifestItem
    {
        $path = $this->paths->normalize($path);

        foreach ($this->getItems() as $item) {
            if ($item->path === $path) {
                return $item;
            }
        }

        return null;
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

        return $this->toItem($item);
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
     * Whether the package is EPUB 3 (version 3.x), whose items carry properties.
     */
    public function isEpub3(): bool
    {
        return str_starts_with(trim((string) $this->opfXml['version']), '3');
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
     * Marks the current state as persisted (called by EpubFile::save()).
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

        foreach ($this->query('/opf:package/opf:guide') as $guide) {
            foreach ($this->query('/opf:package/opf:guide/opf:reference') as $reference) {
                if ($this->tryHrefToPath((string) $reference['href']) === $path) {
                    unset($reference[0]);
                }
            }

            // OPF 2 requires at least one reference in a guide.
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
    }

    private function findNode(string $id): ?SimpleXMLElement
    {
        foreach ($this->itemNodes() as $node) {
            if ((string) $node['id'] === $id) {
                return $node;
            }
        }

        return null;
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

    private function guessMediaType(string $path): string
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
