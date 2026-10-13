<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use DOMAttr;
use DOMElement;
use PhpEpub\Exception;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;

/**
 * Finds the links of an XHTML or SVG document that name an element (a "#fragment") the target document does not
 * have (EPUBCheck RSC-012). Targets that are not XML documents, are too large or cannot be read are not judged.
 *
 * @internal Used by Validator and Repair\Repairer.
 */
final class FragmentReferences
{
    private const int MAX_BYTES = 8388608;

    private const array LINKING = ['a', 'area', 'use', 'image'];

    private const array EXTENSIONS = ['xhtml', 'html', 'htm', 'xml', 'svg'];

    /**
     * The element ids of the documents read so far, by book path; null for a document that is not judged.
     *
     * @var array<string, array<string, true>|null>
     */
    private array $ids = [];

    public function __construct(
        private readonly string $rootDirectory,
        private readonly PathResolver $paths = new PathResolver(),
        private readonly XmlParser $xmlParser = new XmlParser()
    ) {
    }

    /**
     * The links with a fragment that names no element of their target.
     *
     * @param DOMElement $root The document element of the document at $path.
     * @param string $path The document's path relative to the book root.
     *
     * @return list<array{attribute: DOMAttr, file: string, fragment: string}> The href attribute, the target's path
     *                                                                         and the missing fragment.
     */
    public function broken(DOMElement $root, string $path): array
    {
        $broken = [];
        $directory = dirname($path) === '.' ? '' : dirname($path) . '/';
        foreach (($root->ownerDocument ?? $root)->getElementsByTagName('*') as $element) {
            if (! in_array(strtolower($element->localName ?? ''), self::LINKING, true)) {
                continue;
            }

            foreach ($element->attributes ?? [] as $attribute) {
                if ($attribute->localName !== 'href') {
                    continue;
                }

                $target = $this->target($attribute->value, $path, $directory);
                if ($target === null) {
                    continue;
                }

                [$file, $fragment] = $target;
                $ids = $this->idsOf($file);
                if ($ids !== null && ! isset($ids[$fragment])) {
                    $broken[] = ['attribute' => $attribute, 'file' => $file, 'fragment' => $fragment];
                }
            }
        }

        return $broken;
    }

    /**
     * [the target's book path, the fragment] of a reference that has a fragment worth checking; null otherwise.
     *
     * @return array{string, string}|null
     */
    private function target(string $reference, string $path, string $directory): ?array
    {
        $reference = trim($reference);
        $hash = strpos($reference, '#');
        if ($hash === false || str_starts_with($reference, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) === 1) {
            return null;
        }

        $fragment = rawurldecode(substr($reference, $hash + 1));
        // "#" and "#top" name the top of a document, and "#epubcfi(…)" or "#svgView(…)" are not element ids.
        if ($fragment === '' || $fragment === 'top' || str_contains($fragment, '(')) {
            return null;
        }

        $file = rawurldecode(explode('?', substr($reference, 0, $hash), 2)[0]);
        try {
            return [$file === '' ? $path : $this->paths->normalize($directory . $file), $fragment];
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return array<string, true>|null
     */
    private function idsOf(string $path): ?array
    {
        if (! array_key_exists($path, $this->ids)) {
            $this->ids[$path] = $this->readIds($path);
        }

        return $this->ids[$path];
    }

    /**
     * @return array<string, true>|null
     */
    private function readIds(string $path): ?array
    {
        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return null;
        }

        try {
            $file = $this->paths->resolve($this->rootDirectory, $path);
            if (! is_file($file) || (int) @filesize($file) > self::MAX_BYTES) {
                return null;
            }

            $root = dom_import_simplexml($this->xmlParser->parse($file));
        } catch (Exception | XmlException) {
            return null;
        }

        $document = $root->ownerDocument;
        if (! $document instanceof \DOMDocument) {
            return null;
        }

        $ids = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes ?? [] as $attribute) {
                if ($attribute->localName === 'id' || ($attribute->localName === 'name' && strtolower($element->localName ?? '') === 'a')) {
                    $ids[$attribute->value] = true;
                }
            }
        }

        return $ids;
    }
}
