<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Reduces untrusted HTML (parsed with DOMDocument::loadHTML()) to a fixed set of text, table and image
 * elements and attributes, in place. Anything else is removed: scripts, styles, forms, embedded documents,
 * media, event handlers, and every URL except plain links (http, https, mailto and "#fragment") and the images
 * the caller's resolver accepts.
 *
 * Elements outside the allowlist that only wrap content (custom elements, "font", "center", ...) are replaced
 * by their children; elements that carry or run something (script, style, iframe, object, ...) are removed
 * with their content.
 *
 * @internal
 */
final readonly class HtmlSanitizer
{
    /**
     * Elements kept; every other element is replaced by its children, except the ones in REMOVED.
     */
    private const array ALLOWED = [
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'bdi', 'bdo', 'blockquote', 'body', 'br', 'caption', 'cite',
        'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure',
        'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'i', 'img', 'ins', 'kbd', 'li',
        'main', 'mark', 'nav', 'ol', 'p', 'pre', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'section', 'small', 'span',
        'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'u', 'ul',
        'var', 'wbr',
    ];

    /**
     * Elements removed together with their content.
     */
    private const array REMOVED = [
        'script', 'style', 'noscript', 'template', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'audio', 'video', 'source', 'track', 'canvas', 'map', 'area', 'select', 'textarea', 'button', 'input',
        'option', 'optgroup', 'datalist', 'output', 'head', 'title', 'link', 'meta', 'base', 'math', 'dialog',
        'picture',
    ];

    /**
     * Attributes kept on every allowed element.
     */
    private const array GLOBAL_ATTRIBUTES = ['id', 'class', 'lang', 'title'];

    /**
     * Attributes kept per element, besides the global ones.
     */
    private const array ELEMENT_ATTRIBUTES = [
        'td' => ['colspan', 'rowspan', 'headers', 'scope', 'abbr'],
        'th' => ['colspan', 'rowspan', 'headers', 'scope', 'abbr'],
        'ol' => ['start', 'type', 'reversed'],
        'li' => ['value'],
        'time' => ['datetime'],
        'col' => ['span'],
        'colgroup' => ['span'],
    ];

    private const array LINK_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * Elements nested deeper than this are removed.
     */
    private const int MAX_DEPTH = 100;

    /**
     * @param Closure(string): ?string $resolveImage Maps an image's src to the value to write instead, or null to
     *                                               drop the image (its alt text stays as plain text).
     * @param Closure(string): string|null $filterStyle Rewrites style attributes; null removes them.
     */
    public function __construct(private Closure $resolveImage, private ?Closure $filterStyle = null)
    {
    }

    /**
     * Parses markup as the content of a <body> and returns that element; its document is an HTML document, so
     * unquoted or oddly written attributes are read the way browsers read them. Nothing is fetched.
     */
    public static function parseBody(string $html): DOMElement
    {
        $document = new DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            // The leading declaration makes the HTML parser read the bytes as UTF-8; the explicit body keeps
            // leading <script> or <style> elements out of the head.
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof DOMElement ? $body : $document->createElement('body');
    }

    /**
     * Sanitizes the descendants of $root (not $root itself).
     */
    public function sanitize(DOMElement $root): void
    {
        $this->cleanChildren($root, 0);
    }

    /**
     * Whether a link target may stay in an href: a fragment, or an http, https or mailto URL. Whitespace and
     * control characters are ignored when reading the scheme, as browsers do.
     */
    public static function isSafeLink(string $href): bool
    {
        $compact = (string) preg_replace('/[\x00-\x20\x7f]+/u', '', $href);
        if ($compact === '') {
            return false;
        }

        if ($compact[0] === '#') {
            return true;
        }

        return preg_match('/^([a-z][a-z0-9+.-]*):/i', $compact, $match) === 1 && in_array(strtolower($match[1]), self::LINK_SCHEMES, true);
    }

    private function cleanChildren(DOMNode $parent, int $depth): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $this->cleanElement($child, $depth + 1);
            } elseif (! $child instanceof DOMText) {
                // Comments, processing instructions, CDATA.
                $parent->removeChild($child);
            }
        }
    }

    private function cleanElement(DOMElement $element, int $depth): void
    {
        $tag = strtolower($element->localName ?? $element->nodeName);
        $parent = $element->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        if ($depth > self::MAX_DEPTH || in_array($tag, self::REMOVED, true)) {
            $parent->removeChild($element);

            return;
        }

        if ($tag === 'svg') {
            $this->replaceSvg($element);

            return;
        }

        if ($tag === 'img') {
            $this->cleanImage($element);

            return;
        }

        $this->cleanChildren($element, $depth);

        if (! in_array($tag, self::ALLOWED, true)) {
            while ($element->firstChild instanceof DOMNode) {
                $parent->insertBefore($element->firstChild, $element);
            }

            $parent->removeChild($element);

            return;
        }

        // The loader's link anchors hold a zero-width space (Dompdf ignores empty ones); an empty one is enough here.
        if ($tag === 'a' && $element->textContent === "\u{200B}") {
            $element->textContent = '';
        }

        $this->cleanAttributes($element, $tag);
    }

    private function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = [...self::GLOBAL_ATTRIBUTES, ...(self::ELEMENT_ATTRIBUTES[$tag] ?? [])];
        $language = $element->getAttribute('xml:lang');

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $element->removeAttributeNode($attribute);

            if ($name === 'style' && $this->filterStyle instanceof Closure) {
                $style = trim(($this->filterStyle)($attribute->value));
                $style === '' || $element->setAttribute('style', $style);
            } elseif ($name === 'href' && $tag === 'a') {
                if (self::isSafeLink($attribute->value)) {
                    $element->setAttribute('href', trim($attribute->value));
                    str_starts_with(trim($attribute->value), '#') || $element->setAttribute('rel', 'noopener noreferrer');
                }
            } elseif ($name === 'dir') {
                in_array(strtolower($attribute->value), ['ltr', 'rtl', 'auto'], true) && $element->setAttribute('dir', strtolower($attribute->value));
            } elseif (in_array($name, $allowed, true) && $this->hasPlainValue($attribute->value)) {
                $element->setAttribute($name, $attribute->value);
            }
        }

        if ($language !== '' && ! $element->hasAttribute('lang') && $this->hasPlainValue($language)) {
            $element->setAttribute('lang', $language);
        }
    }

    /**
     * Attribute values are escaped on output; control characters are still dropped.
     */
    private function hasPlainValue(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value) === 0;
    }

    private function cleanImage(DOMElement $image): void
    {
        $source = trim($image->getAttribute('src'));
        $alt = $image->getAttribute('alt');
        $resolved = $source === '' ? null : ($this->resolveImage)($source);
        $parent = $image->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        if ($resolved === null) {
            $this->replaceWithText($image, $alt);

            return;
        }

        $kept = [];
        foreach (iterator_to_array($image->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $keep = in_array($name, ['alt', 'title', 'class', 'id'], true)
                || (in_array($name, ['width', 'height'], true) && preg_match('/^\d{1,5}$/', $attribute->value) === 1);
            $image->removeAttributeNode($attribute);
            $keep && $this->hasPlainValue($attribute->value) && $kept[$name] = $attribute->value;
        }

        $image->setAttribute('src', $resolved);
        foreach ($kept + ['alt' => ''] as $name => $value) {
            $image->setAttribute($name, $value);
        }
    }

    /**
     * An inline SVG (the usual cover page markup) becomes an img of its first <image>; any other SVG is removed.
     */
    private function replaceSvg(DOMElement $svg): void
    {
        $parent = $svg->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        $source = '';
        foreach ($svg->getElementsByTagName('image') as $image) {
            $source = trim($image->getAttribute('xlink:href') !== '' ? $image->getAttribute('xlink:href') : $image->getAttribute('href'));
            if ($source !== '') {
                break;
            }
        }

        $document = $svg->ownerDocument;
        $resolved = $source === '' ? null : ($this->resolveImage)($source);
        if ($resolved === null || ! $document instanceof DOMDocument) {
            $parent->removeChild($svg);

            return;
        }

        $img = $document->createElement('img');
        $img->setAttribute('src', $resolved);
        $img->setAttribute('alt', '');
        $parent->replaceChild($img, $svg);
    }

    private function replaceWithText(DOMElement $element, string $text): void
    {
        $parent = $element->parentNode;
        $document = $element->ownerDocument;
        $text = trim($text);
        if ($text !== '' && $parent instanceof DOMNode && $document instanceof DOMDocument) {
            $parent->replaceChild($document->createTextNode('[' . $text . ']'), $element);

            return;
        }

        $parent?->removeChild($element);
    }
}
