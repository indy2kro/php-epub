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
