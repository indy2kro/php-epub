<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use DOMAttr;
use DOMDocument;
use DOMElement;
use PhpEpub\ConversionException;
use PhpEpub\InvalidEpubException;
use PhpEpub\Manifest;
use PhpEpub\ManifestItem;
use PhpEpub\Metadata;
use PhpEpub\Parser;
use PhpEpub\Spine;
use PhpEpub\Util\PathResolver;
use PhpEpub\XmlParser;

/**
 * Reads an extracted EPUB the way a reader would: the spine documents in order,
 * plus the title and authors. Used by the HTML-based PDF converters.
 *
 * Book content is untrusted, so scripts, embedded documents and CSS that can
 * load resources are removed, and resource attributes (src, href outside links,
 * poster, ...) are rewritten to absolute paths of files inside the book; anything
 * else (absolute paths, other schemes, remote URLs, paths escaping the book,
 * missing files) is blanked so renderers never read outside the book.
 */
final readonly class EpubDocumentLoader
{
    private const array XHTML_MEDIA_TYPES = ['application/xhtml+xml', 'text/html'];

    /**
     * Elements that run code or embed other documents or resources.
     */
    private const array REMOVED_ELEMENTS = ['script', 'link', 'base', 'meta', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet'];

    /**
     * Attributes holding a resource URL, rewritten to a file inside the book (or blanked).
     * "href" is handled separately: it is a resource on every element except links.
     */
    private const array SOURCE_ATTRIBUTES = ['src', 'xlink:href', 'poster', 'background', 'data', 'lowsrc', 'dynsrc'];

    /**
     * Attributes holding several URLs; renderers fall back to "src" without them.
     */
    private const array REMOVED_ATTRIBUTES = ['srcset'];

    public function __construct(
        private XmlParser $xmlParser = new XmlParser(),
        private PathResolver $paths = new PathResolver()
    ) {
    }

    /**
     * @throws ConversionException If the directory holds no readable book.
     */
    public function load(string $epubDirectory): EpubDocument
    {
        $root = is_dir($epubDirectory) ? realpath($epubDirectory) : false;
        if ($root === false) {
            throw new ConversionException("EPUB directory does not exist: {$epubDirectory}");
        }

        if (is_file($root . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'container.xml')) {
            return $this->loadPackage($root);
        }

        // Legacy layout: a single content.xhtml without an OPF package.
        if (is_file($root . DIRECTORY_SEPARATOR . 'content.xhtml')) {
            return new EpubDocument('', [], [$this->prepareChapter($root, 'content.xhtml')]);
        }

        throw new ConversionException("No EPUB package found in: {$epubDirectory}");
    }

    private function loadPackage(string $root): EpubDocument
    {
        $opfPath = (new Parser($this->xmlParser))->parse($root);
        $opfFile = $this->paths->resolve($root, $opfPath);
        $opfXml = $this->xmlParser->parse($opfFile);

        $metadata = new Metadata($opfXml, $opfFile);
        $spine = new Spine($opfXml, new Manifest($opfXml, $opfPath));

        $chapters = [];
        foreach ($spine->getItems() as $spineItem) {
            $item = $spineItem->item;
            if ($item instanceof ManifestItem && $item->path !== '' && in_array($item->mediaType, self::XHTML_MEDIA_TYPES, true)) {
                $chapters[] = $this->prepareChapter($root, $item->path);
            }
        }

        return new EpubDocument($metadata->getTitle(), array_values($metadata->getAuthors()), $chapters);
    }

    /**
     * Returns the <body> HTML of a document, with active content removed and every
     * resource reference confined to the book.
     *
     * The chapter is parsed with libxml's HTML parser rather than matched with regular
     * expressions, so unquoted or unusually spelled attributes cannot slip through.
     */
    private function prepareChapter(string $root, string $path): string
    {
        $file = $this->paths->resolve($root, $path);
        $content = is_file($file) ? @file_get_contents($file) : false;
        if ($content === false) {
            throw new ConversionException("Failed to read content from: {$path}");
        }

        $document = new DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            // The leading declaration makes the HTML parser read the bytes as UTF-8.
            $document->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

        $directory = dirname($path) === '.' ? '' : dirname($path) . '/';

        foreach (iterator_to_array($body->getElementsByTagName('*')) as $element) {
            $this->sanitizeElement($element, $root, $directory);
        }

        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }

    /**
     * Removes elements that load or run something, and confines the attributes renderers follow.
     */
    private function sanitizeElement(DOMElement $element, string $root, string $directory): void
    {
        $tag = strtolower($element->localName ?? $element->nodeName);

        if (in_array($tag, self::REMOVED_ELEMENTS, true) || ($tag === 'style' && $this->isUnsafeCss($element->textContent))) {
            $element->parentNode?->removeChild($element);

            return;
        }

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (
                str_starts_with($name, 'on')
                || in_array($name, self::REMOVED_ATTRIBUTES, true)
                || ($name === 'style' && $this->isUnsafeCss($attribute->value))
            ) {
                $element->removeAttributeNode($attribute);
            } elseif (in_array($name, self::SOURCE_ATTRIBUTES, true) || ($name === 'href' && ! in_array($tag, ['a', 'area'], true))) {
                $element->setAttribute($attribute->nodeName, $this->resolveSource($root, $directory, $attribute->value));
            }
        }
    }

    /**
     * CSS that can make a renderer load a resource (url(), @import, image-set()) or hide one behind escapes.
     */
    private function isUnsafeCss(string $css): bool
    {
        return preg_match('/url\s*\(|@import|image-set\s*\(|expression\s*\(|\\\\/i', $css) === 1;
    }

    private function resolveSource(string $root, string $directory, string $source): string
    {
        $source = trim($source);

        if (str_starts_with(strtolower($source), 'data:')) {
            return $source;
        }

        // Remote URLs, file:// and any other scheme.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $source) === 1) {
            return '';
        }

        try {
            $file = $this->paths->resolve($root, $directory . rawurldecode(explode('#', $source, 2)[0]));
        } catch (InvalidEpubException) {
            return '';
        }

        $real = realpath($file);
        if ($real === false || ! is_file($real) || ! str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return '';
        }

        return str_replace('\\', '/', $real);
    }
}
