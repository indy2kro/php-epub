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
        'js' => 'application/javascript',
        'ncx' => 'application/x-dtbncx+xml',
        'smil' => 'application/smil+xml',
        'svg' => 'image/svg+xml',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'otf' => 'font/otf',
        'ttf' => 'font/ttf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'm4a' => 'audio/mp4',
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
        $segments = explode('/', $this->paths->normalize($path));
        $base = $this->opfDirectory === '' ? [] : explode('/', $this->opfDirectory);

        // Drop the common leading directories, then climb out of the rest of the OPF directory.
        while ($base !== [] && count($segments) > 1 && $base[0] === $segments[0]) {
            array_shift($base);
            array_shift($segments);
        }

        $relative = array_merge(array_fill(0, count($base), '..'), array_map(rawurlencode(...), $segments));

        return implode('/', $relative);
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
