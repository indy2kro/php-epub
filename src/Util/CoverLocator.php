<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use DOMDocument;
use PhpEpub\Exception;
use PhpEpub\InvalidEpubException;
use PhpEpub\Manifest;
use PhpEpub\ManifestItem;
use PhpEpub\Metadata;

/**
 * Finds the cover image of a book, for EpubFile and EpubReader alike.
 *
 * @internal
 */
final class CoverLocator
{
    private const string COVER_PROPERTY = 'cover-image';

    /**
     * The manifest item with the EPUB 3 "cover-image" property; else the item named by the EPUB 2
     * <meta name="cover"> (by id, or by href as some books write it); else the EPUB 2 <guide> cover
     * reference, which names either the image or a cover page whose first image is used.
     *
     * @param \Closure(string): string $readMarkup Reads an XHTML document of the book by path. A document it cannot read
     *                                             (missing, or over the size limit: it throws an Exception) has no
     *                                             first image, so the lookup is best effort and never fails.
     */
    public static function find(Manifest $manifest, Metadata $metadata, \Closure $readMarkup): ?ManifestItem
    {
        foreach ($manifest->getItems() as $item) {
            if (in_array(self::COVER_PROPERTY, explode(' ', $item->properties), true)) {
                return $item;
            }
        }

        $cover = $metadata->getMeta('cover');
        if ($cover !== null) {
            $item = $manifest->get($cover) ?? $manifest->findByHref($cover);
            if ($item instanceof ManifestItem) {
                return $item;
            }
        }

        $guidePath = $manifest->getGuidePath('cover');
        $item = $guidePath === null ? null : $manifest->findByPath($guidePath);
        if (! $item instanceof ManifestItem) {
            return null;
        }

        return str_starts_with($item->mediaType, 'image/') ? $item : self::firstImageOf($manifest, $item, $readMarkup);
    }

    /**
     * The manifest item of the first image (<img src>, or SVG <image href>) in an XHTML page.
     *
     * @param \Closure(string): string $readMarkup
     */
    private static function firstImageOf(Manifest $manifest, ManifestItem $page, \Closure $readMarkup): ?ManifestItem
    {
        if (! in_array($page->mediaType, ['application/xhtml+xml', 'text/html'], true)) {
            return null;
        }

        $document = new DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . TextEncoding::toUtf8($readMarkup($page->path)), LIBXML_NONET);
        } catch (Exception) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        $sources = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $sources[] = $image->getAttribute('src');
        }
        foreach ($document->getElementsByTagName('image') as $image) {
            $sources[] = $image->getAttribute('xlink:href') ?: $image->getAttribute('href');
        }

        $directory = dirname($page->path) === '.' ? '' : dirname($page->path) . '/';
        foreach ($sources as $source) {
            try {
                $item = $manifest->findByPath($directory . rawurldecode(explode('#', $source, 2)[0]));
            } catch (InvalidEpubException) {
                continue;
            }

            if ($item instanceof ManifestItem && str_starts_with($item->mediaType, 'image/')) {
                return $item;
            }
        }

        return null;
    }
}
