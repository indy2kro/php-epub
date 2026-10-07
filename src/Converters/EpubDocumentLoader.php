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
use PhpEpub\Util\FileSystemHelper;
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
            $styles = [];
            $chapter = $this->prepareChapter($root, 'content.xhtml', $styles, $chapterTitle);

            return new EpubDocument('', [], [$chapter], array_values($styles), [$chapterTitle]);
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
        $chapterTitles = [];
        $styles = [];
        foreach ($spine->getItems() as $spineItem) {
            $item = $spineItem->item;
            if ($item instanceof ManifestItem && $item->path !== '' && in_array($item->mediaType, self::XHTML_MEDIA_TYPES, true)) {
                $chapters[] = $this->prepareChapter($root, $item->path, $styles, $chapterTitle);
                $chapterTitles[] = $chapterTitle;
            }
        }

        return new EpubDocument($metadata->getTitle(), array_values($metadata->getAuthors()), $chapters, array_values($styles), $chapterTitles);
    }

    /**
     * Returns the <body> HTML of a document, with active content removed and every
     * resource reference confined to the book, and adds its stylesheets to $styles.
     *
     * The chapter is parsed with libxml's HTML parser rather than matched with regular
     * expressions, so unquoted or unusually spelled attributes cannot slip through.
     *
     * @param array<string, string> $styles Sanitised CSS keyed by its source, so shared stylesheets appear once.
     * @param string|null $title Set to the chapter title (see EpubDocument::$chapterTitles).
     *
     * @param-out string $title
     */
    private function prepareChapter(string $root, string $path, array &$styles, ?string &$title = null): string
    {
        $title = '';

        $file = $this->paths->resolve($root, $path);
        $content = FileSystemHelper::readFile($file) ?? throw new ConversionException("Failed to read content from: {$path}");

        $document = new DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            // The leading declaration makes the HTML parser read the bytes as UTF-8.
            $document->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        $directory = dirname($path) === '.' ? '' : dirname($path) . '/';
        $this->collectStyles($document, $root, $directory, $styles);
        $title = $this->chapterTitle($document);

        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

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

        if (in_array($tag, self::REMOVED_ELEMENTS, true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        if ($tag === 'style') {
            $element->textContent = $this->sanitizeCss($element->textContent, $root, $directory);
        }

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (str_starts_with($name, 'on') || in_array($name, self::REMOVED_ATTRIBUTES, true)) {
                $element->removeAttributeNode($attribute);
            } elseif ($name === 'style') {
                $element->setAttribute($attribute->nodeName, $this->sanitizeCss($attribute->value, $root, $directory));
            } elseif (in_array($name, self::SOURCE_ATTRIBUTES, true) || ($name === 'href' && ! in_array($tag, ['a', 'area'], true))) {
                $element->setAttribute($attribute->nodeName, $this->resolveSource($root, $directory, $attribute->value));
            }
        }
    }

    /**
     * The first h1-h3 heading of the body, else the <title>, with whitespace collapsed; "" when there is neither.
     * Headings come first because many books repeat the book title in every <title>.
     */
    private function chapterTitle(DOMDocument $document): string
    {
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body instanceof DOMElement) {
            foreach ($body->getElementsByTagName('*') as $element) {
                if (in_array(strtolower($element->localName ?? ''), ['h1', 'h2', 'h3'], true)) {
                    $heading = $this->collapseWhitespace($element->textContent);
                    if ($heading !== '') {
                        return $heading;
                    }
                }
            }
        }

        return $this->collapseWhitespace((string) $document->getElementsByTagName('title')->item(0)?->textContent);
    }

    private function collapseWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Adds the document's linked stylesheets (rel="stylesheet", files inside the book) and
     * <head> <style> blocks to $styles, sanitised. <style> blocks in the body stay in place.
     *
     * @param array<string, string> $styles
     */
    private function collectStyles(DOMDocument $document, string $root, string $directory, array &$styles): void
    {
        foreach ($document->getElementsByTagName('link') as $link) {
            $relations = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel')))) ?: [];
            if (! in_array('stylesheet', $relations, true) || in_array('alternate', $relations, true)) {
                continue;
            }

            $file = $this->resolveSource($root, $directory, $link->getAttribute('href'));
            if ($file === '' || str_starts_with($file, 'data:') || isset($styles[$file])) {
                continue;
            }

            // url() in a stylesheet is relative to the stylesheet, not to the chapter.
            $relative = substr($file, strlen(str_replace('\\', '/', $root)) + 1);
            $cssDirectory = dirname($relative) === '.' ? '' : dirname($relative) . '/';
            $css = FileSystemHelper::readFile($file);
            if ($css !== null) {
                $styles[$file] = $this->sanitizeCss($css, $root, $cssDirectory);
            }
        }

        $head = $document->getElementsByTagName('head')->item(0);
        if ($head instanceof DOMElement) {
            foreach ($head->getElementsByTagName('style') as $style) {
                $css = $this->sanitizeCss($style->textContent, $root, $directory);
                $styles['style:' . md5($css)] = $css;
            }
        }
    }

    /**
     * Makes book CSS safe to render: escapes are decoded (and stray backslashes dropped, so the
     * renderer cannot decode anything again), comments, @import and image-set() are removed, and
     * every url() is rewritten to a file inside the book (or blanked).
     */
    private function sanitizeCss(string $css, string $root, string $directory): string
    {
        $css = (string) preg_replace_callback(
            '/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/su',
            static fn (array $match): string => $match[1] !== ''
                ? html_entity_decode('&#x' . $match[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : $match[2],
            $css
        );
        $css = str_replace('\\', '', $css);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $css = (string) preg_replace_callback(
            '/url\s*\(\s*(["\']?)(.*?)\1\s*\)/is',
            fn (array $match): string => 'url("' . str_replace('"', '%22', $this->resolveSource($root, $directory, $match[2])) . '")',
            $css
        );

        $css = (string) preg_replace('/(?:-webkit-)?image-set\s*\((?:[^()]|\([^()]*\))*\)/i', 'none', $css);

        return trim((string) preg_replace('/@import\b[^;]*;?/i', '', $css));
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
