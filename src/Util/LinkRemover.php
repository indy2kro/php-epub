<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use DOMDocument;
use PhpEpub\InvalidEpubException;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;

/**
 * Turns the links of an XHTML document to files that are not there any more into plain text: an <a> or <area> is
 * replaced by its content, a <link> is removed, and elements with src/xlink:href/data/poster attributes
 * pointing to removed files are removed or the attribute is cleared.
 *
 * @internal Used by Merge\Merger and Split\Splitter.
 */
final readonly class LinkRemover
{
    private const string XLINK = 'http://www.w3.org/1999/xlink';

    public function __construct(
        private PathResolver $paths = new PathResolver(),
        private XmlParser $xmlParser = new XmlParser()
    ) {
    }

    /**
     * The document without its links to the removed files, or null when it has none (or is not well-formed).
     *
     * @param string $path The document's path relative to the book root.
     * @param array<string, true> $removed The removed files' paths relative to the book root.
     */
    public function remove(string $path, string $content, array $removed): ?string
    {
        $mentioned = false;
        foreach ($removed as $removedPath => $unused) {
            $name = basename($removedPath);
            if (str_contains($content, $name) || str_contains($content, rawurlencode($name))) {
                $mentioned = true;
                break;
            }
        }

        if (! $mentioned) {
            return null;
        }

        try {
            $root = dom_import_simplexml($this->xmlParser->parseString($content, $path));
        } catch (XmlException) {
            return null;
        }

        $document = $root->ownerDocument ?? new DOMDocument();
        $changed = false;

        // Elements with href attribute (a, area, link, base)
        $hrefElements = ['a', 'area', 'link', 'base'];

        // Elements with src attribute (img, script, iframe, embed, source, audio, video, track, input)
        $srcElements = ['img', 'script', 'iframe', 'embed', 'source', 'audio', 'video', 'track', 'input'];

        // Elements with xlink:href attribute (SVG: image, use, a, etc.)
        $xlinkElements = ['image', 'use', 'a', 'animate', 'animateMotion', 'animateTransform', 'set'];

        // Elements with data attribute (object)
        $dataElements = ['object'];

        // Elements with poster attribute (video)
        $posterElements = ['video'];

        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            if (! $element->parentNode instanceof \DOMNode) {
                continue;
            }

            $name = strtolower($element->localName ?? '');
            $namespace = $element->namespaceURI ?? '';

            // Check href attribute
            if (in_array($name, $hrefElements, true)) {
                $href = $element->getAttribute('href');
                if ($href !== '') {
                    $target = $this->target($href, $path);
                    if ($target !== null && isset($removed[$target])) {
                        if ($name === 'a' || $name === 'area') {
                            while ($element->firstChild instanceof \DOMNode) {
                                $element->parentNode->insertBefore($element->firstChild, $element);
                            }
                        }
                        $element->parentNode->removeChild($element);
                        $changed = true;
                        continue;
                    }
                }
            }

            // Check src attribute
            if (in_array($name, $srcElements, true)) {
                $src = $element->getAttribute('src');
                if ($src !== '') {
                    $target = $this->target($src, $path);
                    if ($target !== null && isset($removed[$target])) {
                        $element->parentNode->removeChild($element);
                        $changed = true;
                        continue;
                    }
                }
            }

            // Check xlink:href attribute
            if (in_array($name, $xlinkElements, true) || $namespace === self::XLINK) {
                $xlinkHref = $element->getAttributeNS(self::XLINK, 'href');
                if ($xlinkHref !== '') {
                    $target = $this->target($xlinkHref, $path);
                    if ($target !== null && isset($removed[$target])) {
                        $element->parentNode->removeChild($element);
                        $changed = true;
                        continue;
                    }
                }
            }

            // Check data attribute
            if (in_array($name, $dataElements, true)) {
                $data = $element->getAttribute('data');
                if ($data !== '') {
                    $target = $this->target($data, $path);
                    if ($target !== null && isset($removed[$target])) {
                        $element->parentNode->removeChild($element);
                        $changed = true;
                        continue;
                    }
                }
            }

            // Check poster attribute
            if (in_array($name, $posterElements, true)) {
                $poster = $element->getAttribute('poster');
                if ($poster !== '') {
                    $target = $this->target($poster, $path);
                    if ($target !== null && isset($removed[$target])) {
                        $element->removeAttribute('poster');
                        $changed = true;
                        continue;
                    }
                }
            }
        }

        return $changed ? ($document->saveXML() ?: null) : null;
    }

    /**
     * The book path a reference points to, or null when it is not a reference to a file of the book.
     */
    public function target(string $reference, string $fromPath): ?string
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
}
