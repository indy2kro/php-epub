<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;

/**
 * Detects the EPUB 3 manifest properties an XHTML content document needs because of what it
 * contains (EPUB 3.3, "Item properties"): EPUBCheck rejects a document without them.
 *
 * @internal
 */
final class ContentDocumentProperties
{
    /**
     * The properties detect() decides; any other property of an item is left to the caller.
     */
    public const array PROPERTIES = ['svg', 'mathml', 'scripted', 'remote-resources'];

    private const string SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    private const string MATHML_NAMESPACE = 'http://www.w3.org/1998/Math/MathML';

    /**
     * Attributes holding a resource the reading system loads (links, a and area, are followed, not loaded).
     */
    private const array RESOURCE_ATTRIBUTES = ['src', 'href', 'data', 'poster'];

    /**
     * The properties (in PROPERTIES order) the document needs, or null when it is not well-formed XML.
     *
     * @return list<string>|null
     */
    public static function detect(string $xhtml): ?array
    {
        try {
            $root = dom_import_simplexml((new XmlParser())->parseString($xhtml, 'XHTML content document'));
        } catch (XmlException) {
            return null;
        }

        // A parsed element always belongs to a document.
        $xpath = new DOMXPath($root->ownerDocument ?? new DOMDocument());
        $hasElement = static fn (string $namespace): bool => $xpath->evaluate("boolean(//*[namespace-uri() = '{$namespace}'])") === true;

        $properties = [];

        if ($hasElement(self::SVG_NAMESPACE)) {
            $properties[] = 'svg';
        }

        if ($hasElement(self::MATHML_NAMESPACE)) {
            $properties[] = 'mathml';
        }

        $scripted = false;
        $remote = false;
        foreach ($root instanceof DOMElement ? $root->getElementsByTagName('*') : [] as $element) {
            $name = strtolower($element->localName ?? '');
            $scripted = $scripted || in_array($name, ['script', 'form'], true);
            $remote = $remote || (! in_array($name, ['a', 'area'], true) && self::loadsRemoteResource($element));
        }

        if ($scripted) {
            $properties[] = 'scripted';
        }

        if ($remote) {
            $properties[] = 'remote-resources';
        }

        return $properties;
    }

    private static function loadsRemoteResource(DOMElement $element): bool
    {
        foreach ($element->attributes ?? [] as $attribute) {
            $isResource = in_array(strtolower($attribute->localName ?? ''), self::RESOURCE_ATTRIBUTES, true);
            if ($isResource && preg_match('#^\s*(?:https?:)?//#i', $attribute->value) === 1) {
                return true;
            }
        }

        return false;
    }
}
