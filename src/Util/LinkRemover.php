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
 * @internal Used by Repair\Repairer, Merge\Merger and Split\Splitter.
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
     * The document without its links to removed files, or null when no links were found or a malformed document is ignored.
     *
     * @param string $path The document's path relative to the book root.
     * @param array<string, true> $removed The removed files' paths relative to the book root.
     * @param bool $failOnMalformed Throw when a document mentions a removed file but cannot be parsed.
     *
     * @throws XmlException When $failOnMalformed is true and the document cannot be parsed.
     */
    public function remove(string $path, string $content, array $removed, bool $failOnMalformed = false): ?string
    {
        // A quick check before parsing. Attribute values reach target() with their character references and
        // percent-encoding decoded (g&#111;ne.png, g%6Fne.png), so the decoded content is searched as well.
        $decoded = rawurldecode(html_entity_decode($content, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        $mentioned = false;
        foreach ($removed as $removedPath => $unused) {
            $name = basename($removedPath);
            if (str_contains($content, $name) || str_contains($content, rawurlencode($name)) || str_contains($decoded, $name)) {
                $mentioned = true;
                break;
            }
        }

        if (! $mentioned) {
            return null;
        }

        try {
            $root = dom_import_simplexml($this->xmlParser->parseString($content, $path));
        } catch (XmlException $exception) {
            if ($failOnMalformed) {
                throw $exception;
            }

            return null;
        }

        $document = $root->ownerDocument ?? new DOMDocument();
        $changed = false;

        // Elements with href attribute (a, area, link, base, and SVG 2 image and use)
        $hrefElements = ['a', 'area', 'link', 'base', 'image', 'use'];

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
                        if (($name === 'a' || $name === 'area') && ($element->hasAttribute('id') || ($name === 'a' && $element->hasAttribute('name')))) {
                            // SVG falls back to xlink:href without href: clear both, or another link would take over.
                            $element->removeAttribute('href');
                            $element->removeAttributeNS(self::XLINK, 'href');
                            $changed = true;
                        } else {
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
                        if ($name === 'a' && ($element->hasAttribute('id') || $element->hasAttribute('name'))) {
                            $element->removeAttributeNS(self::XLINK, 'href');
                            $changed = true;
                        } else {
                            if ($name === 'a') {
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

            foreach (['srcset', 'imagesrcset'] as $attribute) {
                $srcset = $element->getAttribute($attribute);
                if ($srcset === '') {
                    continue;
                }

                $updated = $this->removeSrcsetReferences($srcset, $path, $removed);
                if ($updated !== null) {
                    $element->setAttribute($attribute, $updated);
                    $changed = true;
                }
            }
        }

        return $changed ? ($document->saveXML() ?: null) : null;
    }

    /**
     * Removes candidates that point to deleted files, preserving the remaining URLs and descriptors.
     *
     * @param array<string, true> $removed
     */
    private function removeSrcsetReferences(string $srcset, string $fromPath, array $removed): ?string
    {
        $length = strlen($srcset);
        $position = 0;
        $changed = false;
        $kept = [];

        while ($position < $length) {
            while ($position < $length && (ctype_space($srcset[$position]) || $srcset[$position] === ',')) {
                $position++;
            }

            if ($position >= $length) {
                break;
            }

            $urlStart = $position;
            while ($position < $length && ! ctype_space($srcset[$position])) {
                $position++;
            }

            $rawUrl = substr($srcset, $urlStart, $position - $urlStart);
            $url = rtrim($rawUrl, ',');
            $hasTrailingComma = strlen($url) !== strlen($rawUrl);
            $descriptor = '';

            if (! $hasTrailingComma) {
                $descriptorStart = $position;
                while ($position < $length && $srcset[$position] !== ',') {
                    $position++;
                }
                $descriptor = trim(substr($srcset, $descriptorStart, $position - $descriptorStart));
            }

            if ($position < $length && $srcset[$position] === ',') {
                $position++;
            }

            if ($url === '') {
                continue;
            }

            $target = $this->target($url, $fromPath);
            if ($target !== null && isset($removed[$target])) {
                $changed = true;

                continue;
            }

            $kept[] = $descriptor === '' ? $url : $url . ' ' . $descriptor;
        }

        return $changed ? implode(', ', $kept) : null;
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
