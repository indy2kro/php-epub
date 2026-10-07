<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use DOMAttr;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMProcessingInstruction;
use DOMXPath;
use PhpEpub\ConversionException;
use PhpEpub\InvalidEpubException;
use PhpEpub\Manifest;
use PhpEpub\ManifestItem;
use PhpEpub\Metadata;
use PhpEpub\Parser;
use PhpEpub\Spine;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\PathResolver;
use PhpEpub\XmlException;
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
 *
 * Renderers follow the references inside SVG images too, so every SVG (file or data: URI)
 * is inlined as a sanitised data: URI whose references are confined the same way, and
 * every other data: URI is re-encoded as base64 (renderers read any source containing
 * "<svg" as SVG markup, whatever its declared type).
 */
final class EpubDocumentLoader
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

    /**
     * Elements with an id that get their link anchor in front of them rather than inside: void
     * elements, links (no nested links) and list or table containers (whose children are items).
     */
    private const array ANCHORED_BEFORE_ELEMENTS = ['a', 'img', 'br', 'hr', 'input', 'wbr', 'ul', 'ol', 'dl', 'table'];

    /**
     * Table parts that cannot hold or be preceded by an anchor.
     */
    private const array UNANCHORED_ELEMENTS = ['thead', 'tbody', 'tfoot', 'tr', 'colgroup', 'col'];

    /**
     * SVG elements that run code or embed (X)HTML.
     */
    private const array REMOVED_SVG_ELEMENTS = ['script', 'foreignobject'];

    /**
     * How deeply data: SVGs may nest inside SVGs.
     */
    private const int SVG_NESTING_LIMIT = 4;

    /**
     * The most SVG markup, in bytes, inlined per book; later SVG references are blanked,
     * so a chapter repeating a large SVG cannot multiply the size of the output.
     */
    private const int SVG_BUDGET = 16 * 1024 * 1024;

    private int $svgBudget = self::SVG_BUDGET;

    public function __construct(
        private readonly XmlParser $xmlParser = new XmlParser(),
        private readonly PathResolver $paths = new PathResolver()
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

        $this->svgBudget = self::SVG_BUDGET;

        if (is_file($root . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'container.xml')) {
            return $this->loadPackage($root);
        }

        // Legacy layout: a single content.xhtml without an OPF package.
        if (is_file($root . DIRECTORY_SEPARATOR . 'content.xhtml')) {
            $styles = [];
            $chapter = $this->prepareChapter($root, 'content.xhtml', $styles, $chapterTitle, ['content.xhtml' => 0]);

            return new EpubDocument('', [], [$chapter], array_values($styles), [$chapterTitle], $root);
        }

        throw new ConversionException("No EPUB package found in: {$epubDirectory}");
    }

    private function loadPackage(string $root): EpubDocument
    {
        $opfPath = (new Parser($this->xmlParser))->parse($root);
        $opfFile = $this->paths->resolve($root, $opfPath);
        $opfXml = $this->xmlParser->parse($opfFile);

        $metadata = new Metadata($opfXml, $opfFile);
        $manifest = new Manifest($opfXml, $opfPath);
        $spine = new Spine($opfXml, $manifest);

        // Book-relative path => chapter index, for links between chapters.
        $chapterIndexes = [];
        foreach ($spine->getItems() as $spineItem) {
            $item = $spineItem->item;
            if ($item instanceof ManifestItem && $item->path !== '' && in_array($item->mediaType, self::XHTML_MEDIA_TYPES, true)) {
                $chapterIndexes[$item->path] ??= count($chapterIndexes);
            }
        }

        $chapters = [];
        $chapterTitles = [];
        $styles = [];
        foreach (array_keys($chapterIndexes) as $path) {
            $chapters[] = $this->prepareChapter($root, (string) $path, $styles, $chapterTitle, $chapterIndexes);
            $chapterTitles[] = $chapterTitle;
        }

        return new EpubDocument(
            $metadata->getTitle(),
            array_values($metadata->getAuthors()),
            $chapters,
            array_values($styles),
            $chapterTitles,
            $root,
            $this->coverImage($root, $manifest, $metadata, $chapters[0] ?? '')
        );
    }

    /**
     * The cover image (absolute path) when the first chapter does not show it, as in many EPUB 3
     * books: the "cover-image" item, else the item named by the EPUB 2 <meta name="cover">. Only
     * JPEG, PNG and GIF, which both renderers can draw as a page; "" otherwise.
     */
    private function coverImage(string $root, Manifest $manifest, Metadata $metadata, string $firstChapter): string
    {
        $cover = null;
        foreach ($manifest->getItems() as $item) {
            if (in_array('cover-image', explode(' ', $item->properties), true)) {
                $cover = $item;
                break;
            }
        }

        $meta = $metadata->getMeta('cover');
        $cover ??= $meta === null ? null : ($manifest->get($meta) ?? $manifest->findByHref($meta));
        if (! $cover instanceof ManifestItem || $cover->path === '' || ! in_array($cover->mediaType, ['image/jpeg', 'image/png', 'image/gif'], true)) {
            return '';
        }

        // The manifest path is already decoded; keep resolveSource() from decoding it again.
        $file = $this->resolveSource($root, '', str_replace('%', '%25', $cover->path));

        return $file === '' || str_contains($firstChapter, htmlspecialchars($file)) ? '' : $file;
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
     * @param array<string, int> $chapterIndexes Book-relative path => index of every chapter (see linkChapters()).
     *
     * @param-out string $title
     */
    private function prepareChapter(string $root, string $path, array &$styles, ?string &$title, array $chapterIndexes): string
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

        $this->linkChapters($document, $body, $directory, $chapterIndexes[$path] ?? 0, $chapterIndexes);

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
     * Makes links between the book's documents work inside the single PDF: every chapter, and every
     * element with an id (or <a name>), gets an anchor whose id is unique across the book (see anchorId()),
     * and links to chapters of the book (and fragment-only links) are rewritten to those anchors.
     * Other links are kept. The book's own ids stay, so its CSS still applies.
     *
     * @param array<string, int> $chapterIndexes
     */
    private function linkChapters(DOMDocument $document, DOMElement $body, string $directory, int $index, array $chapterIndexes): void
    {
        foreach (iterator_to_array($body->getElementsByTagName('*')) as $element) {
            $tag = strtolower($element->localName ?? '');
            $id = $element->getAttribute('id') !== '' ? $element->getAttribute('id') : ($tag === 'a' ? $element->getAttribute('name') : '');

            if ($id !== '' && ! in_array($tag, self::UNANCHORED_ELEMENTS, true)) {
                $anchor = $this->anchor($document, self::anchorId($index, $id));
                if (in_array($tag, self::ANCHORED_BEFORE_ELEMENTS, true)) {
                    $element->parentNode?->insertBefore($anchor, $element);
                } else {
                    $element->insertBefore($anchor, $element->firstChild);
                }
            }

            if (in_array($tag, ['a', 'area'], true) && $element->hasAttribute('href')) {
                $target = $this->internalTarget($element->getAttribute('href'), $directory, $index, $chapterIndexes);
                if ($target !== null) {
                    $element->setAttribute('href', $target);
                }
            }
        }

        $body->insertBefore($this->anchor($document, self::anchorId($index)), $body->firstChild);
    }

    /**
     * An empty-looking link target: a zero-width space, since Dompdf ignores empty anchors.
     */
    private function anchor(DOMDocument $document, string $id): DOMElement
    {
        $anchor = $document->createElement('a');
        $anchor->setAttribute('id', $id);
        $anchor->appendChild($document->createTextNode("\u{200B}"));

        return $anchor;
    }

    /**
     * "#<anchor id>" for a link to a chapter of the book (or to an element of one), or null for any
     * other link (remote, absolute, outside the book, or to a file that is not a chapter).
     *
     * @param array<string, int> $chapterIndexes
     */
    private function internalTarget(string $href, string $directory, int $index, array $chapterIndexes): ?string
    {
        $href = trim($href);
        if (str_starts_with($href, '#')) {
            return '#' . self::anchorId($index, rawurldecode(substr($href, 1)));
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1) {
            return null;
        }

        [$file, $fragment] = array_pad(explode('#', $href, 2), 2, '');
        try {
            $path = $this->paths->normalize($directory . rawurldecode(explode('?', $file, 2)[0]));
        } catch (InvalidEpubException) {
            return null;
        }

        $target = $chapterIndexes[$path] ?? null;

        return $target === null ? null : '#' . self::anchorId($target, $fragment === '' ? null : rawurldecode($fragment));
    }

    /**
     * "epub-c<chapter>" for a chapter, "epub-c<chapter>-<id>" for an element, with characters other
     * than letters, digits, "." and "-" written as "_<hex byte>", so ids are unique across chapters.
     */
    private static function anchorId(int $chapter, ?string $id = null): string
    {
        $encoded = $id === null ? '' : '-' . preg_replace_callback(
            '/[^A-Za-z0-9.-]/',
            static fn (array $match): string => sprintf('_%02x', ord($match[0])),
            $id
        );

        return "epub-c{$chapter}{$encoded}";
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
     *
     * @param int $svgDepth How many SVG documents enclose the CSS (see resolveSource()).
     */
    private function sanitizeCss(string $css, string $root, string $directory, int $svgDepth = 0): string
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
            function (array $match) use ($root, $directory, $svgDepth): string {
                $source = $this->resolveSource($root, $directory, $match[2], $svgDepth);

                return str_starts_with($source, '#') ? "url({$source})" : 'url("' . str_replace('"', '%22', $source) . '")';
            },
            $css
        );

        $css = (string) preg_replace('/(?:-webkit-)?image-set\s*\((?:[^()]|\([^()]*\))*\)/i', 'none', $css);

        return trim((string) preg_replace('/@import\b[^;]*;?/i', '', $css));
    }

    /**
     * Rewrites a resource reference to the absolute path of a file inside the book, a data: URI
     * (SVG files are inlined, see the class comment) or "" when it is not confined to the book.
     *
     * @param int $svgDepth How many SVG documents enclose the reference. Inside an SVG, references
     *                      to its own elements (#id) are kept and SVG files are not inlined.
     */
    private function resolveSource(string $root, string $directory, string $source, int $svgDepth = 0): string
    {
        $source = trim($source);

        if (str_starts_with(strtolower($source), 'data:')) {
            return $this->sanitizeDataUri($root, $directory, $source, $svgDepth);
        }

        if ($svgDepth > 0 && preg_match('/^#[\w.:-]*$/u', $source) === 1) {
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
        // A path holding "<svg" would be read as SVG markup.
        if ($real === false || ! is_file($real) || ! str_starts_with($real, $root . DIRECTORY_SEPARATOR) || strpbrk($real, '<>') !== false) {
            return '';
        }

        if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'svg') {
            return $svgDepth === 0 ? $this->inlineSvgFile($root, $real) : '';
        }

        return str_replace('\\', '/', $real);
    }

    /**
     * An SVG file as a sanitised data: URI, with references relative to the file; "" when it is
     * unreadable, not a usable SVG document, or over the remaining SVG budget.
     */
    private function inlineSvgFile(string $root, string $file): string
    {
        $size = filesize($file);
        $svg = $size !== false && $size <= $this->svgBudget ? FileSystemHelper::readFile($file) : null;
        if ($svg === null) {
            return '';
        }

        $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
        $directory = dirname($relative) === '.' ? '' : dirname($relative) . '/';

        return $this->svgDataUri($root, $directory, $svg, 1);
    }

    /**
     * Decodes a data: URI and re-encodes it as base64; SVG content is sanitised first.
     * "" when the URI is malformed.
     */
    private function sanitizeDataUri(string $root, string $directory, string $uri, int $svgDepth): string
    {
        if (preg_match('#^data:([a-z0-9.+-]+/[a-z0-9.+-]+)((?:;[^,;]*)*),(.*)$#is', $uri, $match) !== 1) {
            return '';
        }

        $mediaType = strtolower($match[1]);
        $data = in_array('base64', array_map(trim(...), explode(';', strtolower($match[2]))), true)
            ? base64_decode((string) preg_replace('/\s+/', '', rawurldecode($match[3])), true)
            : rawurldecode($match[3]);
        if ($data === false) {
            return '';
        }

        if ($mediaType === 'image/svg+xml') {
            return $svgDepth < self::SVG_NESTING_LIMIT ? $this->svgDataUri($root, $directory, $data, $svgDepth + 1) : '';
        }

        return "data:{$mediaType};base64," . base64_encode($data);
    }

    /**
     * Sanitised SVG markup as a base64 data: URI, charged to the SVG budget; "" when the markup
     * is not a well-formed SVG document (entity declarations are rejected) or over the budget.
     *
     * @param int $svgDepth The nesting depth of this SVG, from 1.
     */
    private function svgDataUri(string $root, string $directory, string $svg, int $svgDepth): string
    {
        try {
            $svgElement = dom_import_simplexml($this->xmlParser->parseString($svg, 'SVG image'));
        } catch (XmlException) {
            return '';
        }

        $document = $svgElement->ownerDocument;
        if (! $document instanceof DOMDocument || strtolower($svgElement->localName ?? '') !== 'svg') {
            return '';
        }

        // Processing instructions (xml-stylesheet) and comments.
        foreach (iterator_to_array((new DOMXPath($document))->query('//processing-instruction() | //comment()') ?: []) as $node) {
            if ($node instanceof DOMProcessingInstruction || $node instanceof DOMComment) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            $this->sanitizeSvgElement($element, $root, $directory, $svgDepth);
        }

        $markup = (string) $document->saveXML($svgElement);
        $fits = strlen($markup) <= $this->svgBudget;
        $this->svgBudget -= $fits ? strlen($markup) : 0;

        return $fits ? 'data:image/svg+xml;base64,' . base64_encode($markup) : '';
    }

    private function sanitizeSvgElement(DOMElement $element, string $root, string $directory, int $svgDepth): void
    {
        $tag = strtolower($element->localName ?? '');

        if (in_array($tag, self::REMOVED_SVG_ELEMENTS, true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        if ($tag === 'style') {
            $element->textContent = $this->sanitizeCss($element->textContent, $root, $directory, $svgDepth);
        }

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->localName ?? '');

            if (str_starts_with($name, 'on')) {
                $element->removeAttributeNode($attribute);
            } elseif ($name === 'href') {
                $attribute->value = $this->resolveSource($root, $directory, $attribute->value, $svgDepth);
            } elseif ($name === 'style' || str_contains(strtolower($attribute->value), 'url(')) {
                $attribute->value = $this->sanitizeCss($attribute->value, $root, $directory, $svgDepth);
            }
        }
    }
}
