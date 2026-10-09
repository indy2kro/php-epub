<?php

declare(strict_types=1);

namespace PhpEpub\Cleanup;

use DOMDocument;
use DOMElement;
use DOMText;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\InvalidEpubException;
use PhpEpub\Manifest;
use PhpEpub\ManifestItem;
use PhpEpub\Metadata;
use PhpEpub\Spine;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\PathResolver;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;

/**
 * Works out which manifest files a book actually uses.
 *
 * It starts from the roots (the spine, the navigation document, the NCX, the cover and the guide
 * references) and follows the references inside the documents it reaches: href, src, srcset, poster,
 * data and xlink:href attributes of XHTML, SVG and SMIL documents, and url(), @import and image-set()
 * in stylesheets, style elements and style attributes. The fallback and the media overlay of a
 * reachable item are reachable too. Content it cannot read reliably (a document that is not
 * well-formed, a script) is searched for the names of manifest files instead, so everything it might
 * refer to counts as reachable. Nothing here throws on bad content.
 *
 * Merge, split and pruning features can reuse it: analyzeFrom() starts from any set of files.
 */
final class ReferenceGraph
{
    private const array REFERENCE_ATTRIBUTES = ['src', 'href', 'poster', 'data', 'background', 'longdesc', 'cite', 'manifest'];

    private const array SRCSET_ATTRIBUTES = ['srcset', 'imagesrcset'];

    private const array XML_TYPES = [
        'application/xhtml+xml', 'image/svg+xml', 'application/smil+xml', 'application/x-dtbncx+xml',
        'application/xml', 'text/xml', 'text/html',
    ];

    private const array OPAQUE_TYPES = [
        'text/javascript', 'application/javascript', 'application/ecmascript', 'text/ecmascript', 'application/x-javascript',
    ];

    public function __construct(
        private readonly string $rootDirectory,
        private readonly Manifest $manifest,
        private readonly Spine $spine,
        private readonly ?Metadata $metadata = null,
        private readonly PathResolver $paths = new PathResolver(),
        private readonly XmlParser $xmlParser = new XmlParser()
    ) {
    }

    /**
     * A graph over a loaded book (including its unsaved edits).
     *
     * @throws Exception If the book is not loaded.
     */
    public static function forBook(EpubFile $book): self
    {
        $directory = $book->getTempDir() ?? throw new Exception('EPUB file must be loaded before analyzing references.');

        return new self($directory, $book->getManifest(), $book->getSpine(), $book->getMetadata());
    }

    /**
     * The files a book needs whatever else it contains: the spine items, the navigation document,
     * the NCX, the cover and the guide references.
     *
     * @return list<string> Manifest paths.
     */
    public function defaultRoots(): array
    {
        $roots = [];
        foreach ($this->spine->get() as $idref) {
            $roots[] = $this->manifest->get($idref)?->path;
        }

        foreach ($this->manifest->getItems() as $item) {
            $properties = explode(' ', $item->properties);
            if (in_array('nav', $properties, true) || in_array('cover-image', $properties, true) || $item->mediaType === 'application/x-dtbncx+xml') {
                $roots[] = $item->path;
            }
        }

        $cover = $this->metadata?->getMeta('cover');
        if ($cover !== null) {
            $roots[] = ($this->manifest->get($cover) ?? $this->manifest->findByHref($cover))?->path;
        }

        foreach ($this->manifest->getGuideReferences() as $reference) {
            $roots[] = $reference->path;
        }

        return $this->manifestPaths($roots);
    }

    /**
     * Analyzes the book from its default roots.
     */
    public function analyze(): ReferenceAnalysis
    {
        return $this->analyzeFrom($this->defaultRoots());
    }

    /**
     * Analyzes the book from the given files (paths relative to the book root; paths that are not
     * manifest files are ignored).
     *
     * @param list<string> $roots
     */
    public function analyzeFrom(array $roots): ReferenceAnalysis
    {
        $queue = $this->manifestPaths($roots);
        $reached = array_fill_keys($queue, true);
        $references = [];
        $unparsable = [];
        $unmanifested = [];

        while ($queue !== []) {
            $path = array_shift($queue);
            $item = $this->manifest->findByPath($path);
            if (! $item instanceof ManifestItem) {
                continue;
            }

            $found = $this->scan($item, $unparsable, $unmanifested);
            $found = array_values(array_unique(array_diff($found, [$path])));
            sort($found, SORT_STRING);
            $references[$path] = $found;

            foreach ($found as $target) {
                if (! isset($reached[$target])) {
                    $reached[$target] = true;
                    $queue[] = $target;
                }
            }
        }

        $all = [];
        foreach ($this->manifest->getItems() as $item) {
            if ($item->path !== '') {
                $all[$item->path] = true;
            }
        }

        $reachable = array_keys($reached);
        $unreachable = array_keys(array_diff_key($all, $reached));
        sort($reachable, SORT_STRING);
        sort($unreachable, SORT_STRING);
        sort($unparsable, SORT_STRING);
        $unmanifested = array_values(array_unique($unmanifested));
        sort($unmanifested, SORT_STRING);
        ksort($references, SORT_STRING);

        return new ReferenceAnalysis($reachable, $unreachable, $references, $unparsable, $unmanifested);
    }

    /**
     * The manifest files one manifest file refers to directly (sorted, without itself), including its
     * fallback and media overlay.
     *
     * @return list<string>
     */
    public function referencesOf(string $path): array
    {
        $item = $this->manifest->findByPath($path);
        if (! $item instanceof ManifestItem) {
            return [];
        }

        $unparsable = [];
        $unmanifested = [];
        $found = array_values(array_unique(array_diff($this->scan($item, $unparsable, $unmanifested), [$item->path])));
        sort($found, SORT_STRING);

        return $found;
    }

    /**
     * @param list<string> $unparsable
     * @param list<string> $unmanifested
     *
     * @return list<string> Manifest paths the item refers to.
     */
    private function scan(ManifestItem $item, array &$unparsable, array &$unmanifested): array
    {
        // A fallback or media overlay is only needed along with the item that names it.
        $paths = [];
        foreach ([$this->manifest->getFallback($item->id), $this->manifest->getMediaOverlay($item->id)] as $id) {
            $related = $id === null ? null : $this->manifest->get($id);
            if ($related instanceof ManifestItem && $related->path !== '') {
                $paths[] = $related->path;
            }
        }

        $content = $item->path === '' ? null : FileSystemHelper::readFile($this->paths->resolve($this->rootDirectory, $item->path));
        if ($content === null) {
            return $paths;
        }

        $references = [];
        $extension = strtolower(pathinfo($item->path, PATHINFO_EXTENSION));
        if ($item->mediaType === 'text/css' || $extension === 'css') {
            $references = $this->cssReferences($content);
        } elseif (in_array($item->mediaType, self::XML_TYPES, true) || in_array($extension, ['xhtml', 'html', 'htm', 'svg', 'smil', 'xml', 'ncx'], true)) {
            $parsed = $this->xmlReferences($content, $item->path);
            if ($parsed === null) {
                $unparsable[] = $item->path;

                return array_merge($paths, $this->mentioned($content));
            }

            [$references, $scripts] = $parsed;
            $paths = array_merge($paths, $this->mentioned($scripts));
        } elseif (in_array($item->mediaType, self::OPAQUE_TYPES, true) || in_array($extension, ['js', 'mjs'], true)) {
            return array_merge($paths, $this->mentioned($content));
        }

        foreach ($references as $reference) {
            $target = $this->targetPath($reference, $item->path);
            if ($target === null) {
                continue;
            }

            if ($this->manifest->findByPath($target) instanceof ManifestItem) {
                $paths[] = $target;
            } elseif (is_file($this->paths->resolve($this->rootDirectory, $target))) {
                $unmanifested[] = $target;
            }
        }

        return $paths;
    }

    /**
     * @return array{list<string>, string}|null The references and the text of the scripts (searched by name),
     *                                          or null when the document is not well-formed.
     */
    private function xmlReferences(string $content, string $path): ?array
    {
        try {
            $root = dom_import_simplexml($this->xmlParser->parseString($content, $path));
        } catch (XmlException) {
            return null;
        }

        $references = [];
        $scripts = '';
        $document = $root->ownerDocument ?? new DOMDocument();
        foreach ($document->getElementsByTagName('*') as $element) {
            $tag = strtolower($element->localName ?? '');
            foreach ($element->attributes ?? [] as $attribute) {
                $name = strtolower($attribute->localName ?? '');
                if (in_array($name, self::REFERENCE_ATTRIBUTES, true)) {
                    $references[] = $attribute->value;
                } elseif (in_array($name, self::SRCSET_ATTRIBUTES, true)) {
                    array_push($references, ...$this->srcsetUrls($attribute->value));
                } elseif ($name === 'style') {
                    array_push($references, ...$this->cssReferences($attribute->value));
                } elseif ($name === 'content' && $tag === 'meta') {
                    // <meta http-equiv="refresh" content="0; url=x.xhtml"> and similar.
                    $scripts .= ' ' . $attribute->value;
                } elseif (str_starts_with($name, 'on')) {
                    // Inline event handlers are code: searched by name like a script.
                    $scripts .= ' ' . $attribute->value;
                }
            }

            if ($tag === 'style' || $tag === 'script') {
                foreach ($element->childNodes as $child) {
                    // DOMText covers CDATA sections too.
                    if (! $child instanceof DOMText) {
                        continue;
                    }

                    if ($tag === 'style') {
                        array_push($references, ...$this->cssReferences($child->data));
                    } else {
                        $scripts .= ' ' . $child->data;
                    }
                }
            }
        }

        return [$references, $scripts];
    }

    /**
     * @return list<string>
     */
    private function cssReferences(string $css): array
    {
        $references = [];
        if (preg_match_all('/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)"\'\s]*))\s*\)/i', $css, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) > 0) {
            foreach ($matches as $match) {
                $references[] = (string) ($match[1] ?? $match[2] ?? $match[3] ?? '');
            }
        }

        if (preg_match_all('/@import\s+(["\'])([^"\']*)\1/i', $css, $matches) > 0) {
            array_push($references, ...$matches[2]);
        }

        if (preg_match_all('/image-set\(([^)]*)\)/i', $css, $sets) > 0) {
            foreach ($sets[1] as $set) {
                if (preg_match_all('/(["\'])([^"\']*)\1/', $set, $matches) > 0) {
                    array_push($references, ...$matches[2]);
                }
            }
        }

        return $references;
    }

    /**
     * @return list<string>
     */
    private function srcsetUrls(string $srcset): array
    {
        $urls = [];
        foreach (explode(',', $srcset) as $candidate) {
            $url = preg_split('/\s+/', trim($candidate))[0] ?? '';
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * The path a reference points to, or null when it is not a local file reference.
     */
    private function targetPath(string $reference, string $fromPath): ?string
    {
        $reference = trim($reference);
        if ($reference === '' || str_starts_with($reference, '#') || str_starts_with($reference, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) === 1) {
            return null;
        }

        $file = rawurldecode(substr($reference, 0, strcspn($reference, '?#')));
        if ($file === '') {
            return null;
        }

        $directory = dirname($fromPath);

        try {
            return $this->paths->normalize(($directory === '.' ? '' : $directory . '/') . $file);
        } catch (InvalidEpubException) {
            return null;
        }
    }

    /**
     * The manifest files whose name (or percent-encoded name) appears in a text.
     *
     * @return list<string>
     */
    private function mentioned(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $found = [];
        foreach ($this->manifest->getItems() as $item) {
            if ($item->path === '') {
                continue;
            }

            $name = basename($item->path);
            if (str_contains($text, $name) || str_contains($text, rawurlencode($name))) {
                $found[] = $item->path;
            }
        }

        return $found;
    }

    /**
     * @param list<string|null> $paths
     *
     * @return list<string>
     */
    private function manifestPaths(array $paths): array
    {
        $found = [];
        foreach ($paths as $path) {
            if ($path !== null && $path !== '' && $this->manifest->findByPath($path) instanceof ManifestItem) {
                $found[] = $path;
            }
        }

        return array_values(array_unique($found));
    }
}
