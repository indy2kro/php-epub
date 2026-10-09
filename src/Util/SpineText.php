<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use PhpEpub\ManifestItem;
use PhpEpub\Spine;

/**
 * The plain text of a book's spine documents, for EpubFile::getText() and EpubReader::getText().
 *
 * @internal
 */
final class SpineText
{
    /**
     * path => text of the XHTML and HTML spine items that exist, in reading order.
     *
     * @param \Closure(string): bool $exists Whether the book has a file at a path.
     * @param \Closure(string): string $text The text of the document at a path.
     *
     * @return array<string, string>
     */
    public static function collect(Spine $spine, bool $linearOnly, \Closure $exists, \Closure $text): array
    {
        $texts = [];
        foreach ($spine->getItems() as $spineItem) {
            $item = $spineItem->item;
            if (! $item instanceof ManifestItem || ! in_array($item->mediaType, ['application/xhtml+xml', 'text/html'], true)) {
                continue;
            }

            if (($spineItem->linear || ! $linearOnly) && $exists($item->path)) {
                $texts[$item->path] = $text($item->path);
            }
        }

        return $texts;
    }
}
