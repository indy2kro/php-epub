<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use SimpleXMLElement;

/**
 * The prefix attribute of the EPUB 3 package element, which maps property prefixes to vocabularies
 * ("rendition: http://www.idpf.org/vocab/rendition/#").
 *
 * @internal
 */
final class PackagePrefixes
{
    /**
     * The prefixes every package may use without declaring them (EPUB 3.3 reserved prefixes).
     */
    public const array RESERVED = ['a11y', 'dcterms', 'marc', 'media', 'onix', 'rendition', 'schema', 'xsd'];

    /**
     * Vocabularies common in the wild that are not reserved; declare() can add them by prefix alone.
     */
    public const array KNOWN = [
        'ibooks' => 'http://vocabulary.itunes.apple.com/rdf/ibooks/vocabulary-extensions-1.0/',
        'prism' => 'http://prismstandard.org/namespaces/basic/2.1/',
    ];

    /**
     * The prefixes the package's prefix attribute declares.
     *
     * @return list<string>
     */
    public static function declared(SimpleXMLElement $package): array
    {
        preg_match_all('/([^\s:]+):\s+\S+/', trim((string) $package['prefix']), $matches);

        return $matches[1];
    }

    /**
     * Whether a property value ("foo:bar") can be used: it has no prefix, or its prefix is reserved or declared.
     */
    public static function isUsable(SimpleXMLElement $package, string $property): bool
    {
        $prefix = self::prefixOf($property);

        return $prefix === null || in_array($prefix, self::RESERVED, true) || in_array($prefix, self::declared($package), true);
    }

    /**
     * The prefix of a property value ("foo" for "foo:bar"); null when it has none (or is a URL).
     */
    public static function prefixOf(string $property): ?string
    {
        $property = trim($property);
        $position = strpos($property, ':');

        return $position === false || $position === 0 || str_starts_with(substr($property, $position + 1), '//') ? null : substr($property, 0, $position);
    }

    /**
     * Declares a prefix unless the package already declares it (with any URI).
     */
    public static function declare(SimpleXMLElement $package, string $prefix, string $uri): void
    {
        $declared = trim((string) $package['prefix']);
        if (preg_match('/(?:^|\s)' . preg_quote($prefix, '/') . ':(?:\s|$)/', $declared) === 1) {
            return;
        }

        $declaration = $declared === '' ? "{$prefix}: {$uri}" : "{$declared} {$prefix}: {$uri}";
        if (isset($package['prefix'])) {
            $package['prefix'] = $declaration;
        } else {
            $package->addAttribute('prefix', $declaration);
        }
    }
}
