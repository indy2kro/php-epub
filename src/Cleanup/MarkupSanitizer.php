<?php

declare(strict_types=1);

namespace PhpEpub\Cleanup;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMText;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;

/**
 * Removes scripts and remote references from XHTML and SVG documents and stylesheets. Every method
 * returns the new content, or null when nothing changes or the document is not well-formed.
 *
 * @internal
 */
final readonly class MarkupSanitizer
{
    private const string REMOTE = '#^\s*(?:https?:|//)#i';

    /**
     * Attributes that hold a URL the browser would run or follow as a script.
     */
    private const array URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'data', 'poster', 'background', 'longdesc', 'cite'];

    /**
     * Elements that make no sense without their remote resource, and are removed with it.
     */
    private const array REMOVED_WITH_RESOURCE = ['img', 'image', 'source', 'track', 'video', 'audio', 'embed', 'iframe', 'object', 'link', 'use', 'script', 'frame'];

    public function __construct(private XmlParser $xmlParser = new XmlParser())
    {
    }

    public function isWellFormed(string $xml, string $source): bool
    {
        try {
            $this->xmlParser->parseString($xml, $source);

            return true;
        } catch (XmlException) {
            return false;
        }
    }

    public function stripScripts(string $xml, string $source): ?string
    {
        return $this->transform($xml, $source, function (DOMDocument $document): bool {
            $changed = false;
            foreach ($this->elements($document) as $element) {
                if (strtolower($element->localName ?? '') === 'script' || $this->isScriptingAnimation($element)) {
                    $element->parentNode?->removeChild($element);
                    $changed = true;
                    continue;
                }

                foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                    if ($this->isScriptAttribute($attribute)) {
                        $element->removeAttributeNode($attribute);
                        $changed = true;
                    }
                }
            }

            return $changed;
        });
    }

    public function removeRemoteReferences(string $xml, string $source): ?string
    {
        return $this->transform($xml, $source, function (DOMDocument $document): bool {
            $changed = false;
            foreach ($this->elements($document) as $element) {
                $changed = $this->removeRemoteFrom($element) || $changed;

                if (strtolower($element->localName ?? '') === 'style') {
                    foreach ($element->childNodes as $child) {
                        if ($child instanceof DOMText) {
                            $css = $this->removeRemoteCss($child->data);
                            if ($css !== null) {
                                $child->data = $css;
                                $changed = true;
                            }
                        }
                    }
                }
            }

            return $changed;
        });
    }

    /**
     * The stylesheet (or style attribute) without @import rules and declarations that load an http(s) resource.
     */
    public function removeRemoteCss(string $css): ?string
    {
        $cleaned = (string) preg_replace('#@import\s+(?:url\(\s*)?["\']?(?:https?:)?//[^;]*;?#i', '', $css);
        $cleaned = (string) preg_replace('#[\w-]+\s*:[^;{}]*\burl\(\s*["\']?(?:https?:)?//[^;{}]*(?:;|(?=\})|$)#i', '', $cleaned);

        return $cleaned === $css ? null : $cleaned;
    }

    /**
     * @param \Closure(DOMDocument): bool $change Returns whether it changed the document.
     */
    private function transform(string $xml, string $source, \Closure $change): ?string
    {
        try {
            $root = dom_import_simplexml($this->xmlParser->parseString($xml, $source));
        } catch (XmlException) {
            return null;
        }

        $document = $root->ownerDocument ?? new DOMDocument();

        return $change($document) ? ($document->saveXML() ?: null) : null;
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(DOMDocument $document): array
    {
        $elements = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            $elements[] = $element;
        }

        return $elements;
    }

    private function isScriptAttribute(DOMAttr $attribute): bool
    {
        $name = strtolower($attribute->localName ?? '');
        if ($attribute->namespaceURI === null && str_starts_with($name, 'on') && strlen($name) > 2) {
            return true;
        }

        // An iframe's srcdoc is a whole document, scripts included.
        if ($name === 'srcdoc') {
            return true;
        }

        if (! in_array($name, self::URL_ATTRIBUTES, true)) {
            return false;
        }

        // Browsers ignore whitespace and control characters inside the scheme.
        $value = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $attribute->value));

        return str_starts_with($value, 'javascript:') || str_starts_with($value, 'vbscript:');
    }

    /**
     * An SVG animation (animate, set, ...) that sets a link to a javascript: URL.
     */
    private function isScriptingAnimation(DOMElement $element): bool
    {
        if (! in_array(strtolower($element->localName ?? ''), ['animate', 'set', 'animatetransform', 'animatemotion'], true)) {
            return false;
        }

        foreach (['to', 'values', 'from', 'by'] as $name) {
            $value = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $element->getAttribute($name)));
            if (str_contains($value, 'javascript:') || str_contains($value, 'vbscript:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes the element when it loads a remote resource, or the remote attribute when it can stay without it.
     * Mirrors ContentDocumentProperties: links (a, area) are followed, not loaded.
     */
    private function removeRemoteFrom(DOMElement $element): bool
    {
        $tag = strtolower($element->localName ?? '');
        $changed = false;
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->localName ?? '');
            if (in_array($tag, ['a', 'area'], true)) {
                continue;
            }

            if (in_array($name, ['srcset', 'imagesrcset'], true)) {
                $candidates = array_values(array_filter(array_map(trim(...), explode(',', $attribute->value)), static fn (string $candidate): bool => $candidate !== ''));
                $kept = array_values(array_filter($candidates, static fn (string $candidate): bool => preg_match(self::REMOTE, $candidate) !== 1));
                if (count($kept) !== count($candidates)) {
                    $kept === [] ? $element->removeAttributeNode($attribute) : $element->setAttributeNS($attribute->namespaceURI, $attribute->nodeName, implode(', ', $kept));
                    $changed = true;
                }
            } elseif (in_array($name, ['src', 'href', 'data', 'poster'], true) && preg_match(self::REMOTE, $attribute->value) === 1) {
                if (in_array($tag, self::REMOVED_WITH_RESOURCE, true)) {
                    $element->parentNode?->removeChild($element);

                    return true;
                }

                $element->removeAttributeNode($attribute);
                $changed = true;
            } elseif ($name === 'style') {
                $css = $this->removeRemoteCss($attribute->value);
                if ($css !== null) {
                    $element->setAttributeNS($attribute->namespaceURI, $attribute->nodeName, $css);
                    $changed = true;
                }
            }
        }

        return $changed;
    }
}
