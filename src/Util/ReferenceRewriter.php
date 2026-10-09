<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMText;
use PhpEpub\InvalidEpubException;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;

/**
 * Rewrites the references to a moved file inside XHTML (or other XML) documents and stylesheets,
 * and the relative references of a document that moves to another directory.
 *
 * References are resource attributes (the set Validator checks: src, poster, data, and href on
 * a, area, link, image and use, including xlink:href), srcset and imagesrcset candidates, and CSS url() / @import "…" values, in
 * stylesheets, style elements and style attributes. Query strings and fragments are kept.
 *
 * @internal
 */
final readonly class ReferenceRewriter
{
    public function __construct(
        private PathResolver $paths = new PathResolver(),
        private XmlParser $xmlParser = new XmlParser()
    ) {
    }

    /**
     * The XML document with its references rewritten, or null when nothing changes or the document
     * is not well-formed (it is then left alone).
     *
     * @param string $path The document's path relative to the book root before the move.
     * @param string $newPath The document's path after the move ($path when it does not move).
     * @param string $from The moved file's old path.
     * @param string $to The moved file's new path.
     */
    public function rewriteXml(string $content, string $path, string $newPath, string $from, string $to): ?string
    {
        try {
            $root = dom_import_simplexml($this->xmlParser->parseString($content, $path));
        } catch (XmlException) {
            return null;
        }

        $document = $root->ownerDocument ?? new DOMDocument();
        $directories = [$this->directory($path), $this->directory($newPath)];
        $changed = false;

        foreach ($document->getElementsByTagName('*') as $element) {
            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                $changed = $this->rewriteAttribute($element, $attribute, $directories, $from, $to) || $changed;
            }

            if (strtolower($element->localName ?? '') === 'style') {
                foreach ($element->childNodes as $child) {
                    // DOMText covers CDATA sections too.
                    if ($child instanceof DOMText) {
                        $css = $this->rewriteCssText($child->data, $directories, $from, $to);
                        if ($css !== $child->data) {
                            $child->data = $css;
                            $changed = true;
                        }
                    }
                }
            }
        }

        return $changed ? $document->saveXML() ?: null : null;
    }

    /**
     * The stylesheet with its url() and @import references rewritten, or null when nothing changes.
     *
     * @param string $path The stylesheet's path relative to the book root before the move.
     * @param string $newPath The stylesheet's path after the move.
     * @param string $from The moved file's old path.
     * @param string $to The moved file's new path.
     */
    public function rewriteCss(string $css, string $path, string $newPath, string $from, string $to): ?string
    {
        $rewritten = $this->rewriteCssText($css, [$this->directory($path), $this->directory($newPath)], $from, $to);

        return $rewritten === $css ? null : $rewritten;
    }

    /**
     * @param array{string, string} $directories The directory the document was in and the one it is in now.
     */
    private function rewriteAttribute(DOMElement $element, DOMAttr $attribute, array $directories, string $from, string $to): bool
    {
        $name = $attribute->localName ?? '';
        $value = $attribute->value;
        $isReference = in_array($name, ['src', 'poster', 'data'], true)
            || ($name === 'href' && in_array($element->localName, ['a', 'area', 'link', 'image', 'use'], true));
        $rewritten = match (true) {
            $isReference => $this->retarget($value, $directories, $from, $to),
            $name === 'style' => $this->rewriteCssText($value, $directories, $from, $to),
            in_array($name, ['srcset', 'imagesrcset'], true) => $this->rewriteSrcset($value, $directories, $from, $to),
            default => $value,
        };
        if ($rewritten === null || $rewritten === $value) {
            return false;
        }

        // setAttribute() escapes the value; assigning DOMAttr::$value would parse "&" as an entity reference.
        $element->setAttributeNS($attribute->namespaceURI, $attribute->nodeName, $rewritten);

        return true;
    }

    /**
     * @param array{string, string} $directories
     */
    private function rewriteSrcset(string $srcset, array $directories, string $from, string $to): string
    {
        $candidates = array_map(function (string $candidate) use ($directories, $from, $to): string {
            // A candidate is a URL, optionally followed by a width or density descriptor.
            $parts = preg_split('/\s+/', trim($candidate), 2) ?: [''];

            return $parts[0] === '' ? $candidate : implode(' ', [$this->retarget($parts[0], $directories, $from, $to) ?? $parts[0], ...array_slice($parts, 1)]);
        }, explode(',', $srcset));

        return implode(',', $candidates) === $srcset ? $srcset : implode(', ', array_map(trim(...), $candidates));
    }

    /**
     * @param array{string, string} $directories
     */
    private function rewriteCssText(string $css, array $directories, string $from, string $to): string
    {
        $css = (string) preg_replace_callback(
            '/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)"\'\s]*))\s*\)/i',
            function (array $match) use ($directories, $from, $to): string {
                // Unmatched groups are null, which tells the quote style apart from an empty value.
                [$quote, $value] = match (true) {
                    $match[1] !== null => ['"', $match[1]],
                    $match[2] !== null => ["'", $match[2]],
                    default => ['', (string) $match[3]],
                };

                return 'url(' . $quote . ($this->retarget($value, $directories, $from, $to) ?? $value) . $quote . ')';
            },
            $css,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL
        );

        return (string) preg_replace_callback(
            '/@import\s+(["\'])([^"\']*)\1/i',
            fn (array $match): string => '@import ' . $match[1] . ($this->retarget($match[2], $directories, $from, $to) ?? $match[2]) . $match[1],
            $css
        );
    }

    /**
     * The new value of a reference, or null when it stays as it is: not a local file reference,
     * or it still points at the same file from the same directory.
     *
     * @param array{string, string} $directories
     */
    private function retarget(string $value, array $directories, string $from, string $to): ?string
    {
        [$oldDirectory, $newDirectory] = $directories;
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $value) === 1) {
            return null;
        }

        $length = strcspn($value, '?#');
        $file = rawurldecode(substr($value, 0, $length));
        if ($file === '') {
            return null;
        }

        try {
            $target = $this->paths->normalize(($oldDirectory === '' ? '' : $oldDirectory . '/') . $file);
        } catch (InvalidEpubException) {
            return null;
        }

        $newTarget = $target === $from ? $to : $target;
        if ($newTarget === $target && $oldDirectory === $newDirectory) {
            return null;
        }

        return $this->paths->relativeHref($newDirectory, $newTarget) . substr($value, $length);
    }

    private function directory(string $path): string
    {
        $directory = dirname($path);

        return $directory === '.' ? '' : $directory;
    }
}
