<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use PhpEpub\InvalidEpubException;

/**
 * Resolves paths taken from an EPUB (or from callers) against the book root,
 * refusing anything that would point outside it.
 */
class PathResolver
{
    /**
     * Normalizes a path relative to the book root, using "/" as separator.
     *
     * @throws InvalidEpubException If the path is absolute or escapes the book root.
     */
    public function normalize(string $path): string
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:/', $path) === 1
            || str_contains($path, '://')
        ) {
            throw new InvalidEpubException("Path resolves outside the EPUB: {$path}");
        }

        $segments = [];
        foreach (preg_split('#[/\\\\]+#', $path) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    throw new InvalidEpubException("Path resolves outside the EPUB: {$path}");
                }
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new InvalidEpubException("Path resolves outside the EPUB: {$path}");
        }

        return implode('/', $segments);
    }

    /**
     * Converts a path relative to the book root into a URL-encoded href relative to
     * $fromDirectory (also relative to the book root; "" for the root itself).
     *
     * @throws InvalidEpubException If the path is absolute or escapes the book root.
     */
    public function relativeHref(string $fromDirectory, string $path): string
    {
        $segments = explode('/', $this->normalize($path));
        $base = $fromDirectory === '' ? [] : explode('/', $this->normalize($fromDirectory));

        // Drop the common leading directories, then climb out of the rest of $fromDirectory.
        while ($base !== [] && count($segments) > 1 && $base[0] === $segments[0]) {
            array_shift($base);
            array_shift($segments);
        }

        return implode('/', array_merge(array_fill(0, count($base), '..'), array_map(rawurlencode(...), $segments)));
    }

    /**
     * Joins a book-relative path onto the directory holding the extracted book.
     *
     * @throws InvalidEpubException If the path is absolute or escapes the book root.
     */
    public function resolve(string $rootDirectory, string $path): string
    {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $this->normalize($path));

        return rtrim($rootDirectory, '/\\') . DIRECTORY_SEPARATOR . $relative;
    }
}
