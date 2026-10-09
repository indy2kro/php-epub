<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\Util\FileSystemHelper;

/**
 * Reads the images a loaded book refers to: the absolute paths EpubDocumentLoader writes into its chapters (files
 * inside the book) and the data: URIs it has re-encoded. Anything else, and anything that is not a JPEG, PNG, GIF,
 * WebP or (loader-sanitised) SVG image, is refused.
 *
 * @internal
 */
final readonly class BookImages
{
    private const array RASTER_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    private const array FONT_TYPES = ['woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf'];

    private string $root;

    public function __construct(string $directory)
    {
        $this->root = rtrim(str_replace('\\', '/', (string) realpath($directory)), '/');
    }

    /**
     * @param int $maxBytes The largest image (decoded) that is read.
     *
     * @return array{mime: string, extension: string, data: string}|null Null when the source is not a usable image of the book.
     */
    public function read(string $source, int $maxBytes): ?array
    {
        $source = trim($source);

        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.*)$#is', $source, $match) === 1) {
            return $this->describe(strtolower($match[1]), base64_decode((string) preg_replace('/\s+/', '', $match[2]), true), $maxBytes);
        }

        // Only files inside the book: remote URLs and stream wrappers are no real paths, and a drive letter is one.
        $real = $this->root === '' || $source === '' || str_contains($source, '://') ? false : realpath($source);
        $real = $real === false ? false : str_replace('\\', '/', $real);
        $size = $real === false ? false : @filesize($real);
        if ($real === false || ! str_starts_with($real, $this->root . '/') || $size === false || $size > $maxBytes || ! is_file($real)) {
            return null;
        }

        $data = FileSystemHelper::readFile($real);
        $info = $data === null ? false : @getimagesizefromstring($data);

        return $data === null || $info === false ? null : $this->describe($info['mime'], $data, $maxBytes);
    }

    /**
     * A font file of the book (WOFF2, WOFF, TrueType or OpenType, by extension) as a data: URI, or null when it is
     * not one, lies outside the book or is larger than $maxBytes.
     */
    public function fontUri(string $path, int $maxBytes): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $real = isset(self::FONT_TYPES[$extension]) && $this->root !== '' ? realpath($path) : false;
        $real = $real === false ? false : str_replace('\\', '/', $real);
        $size = $real === false ? false : @filesize($real);
        if ($real === false || ! str_starts_with($real, $this->root . '/') || $size === false || $size > $maxBytes || ! is_file($real)) {
            return null;
        }

        $data = FileSystemHelper::readFile($real);

        return $data === null ? null : 'data:' . self::FONT_TYPES[$extension] . ';base64,' . base64_encode($data);
    }

    /**
     * @return array{mime: string, extension: string, data: string}|null
     */
    private function describe(string $mime, string|false $data, int $maxBytes): ?array
    {
        if ($data === false || $data === '' || strlen($data) > $maxBytes) {
            return null;
        }

        if ($mime === 'image/svg+xml') {
            return ['mime' => $mime, 'extension' => 'svg', 'data' => $data];
        }

        $info = isset(self::RASTER_TYPES[$mime]) ? @getimagesizefromstring($data) : false;

        return $info !== false && $info['mime'] === $mime ? ['mime' => $mime, 'extension' => self::RASTER_TYPES[$mime], 'data' => $data] : null;
    }
}
