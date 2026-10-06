<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use PhpEpub\Exception;

/**
 * Resolves paths taken from an EPUB (or from callers) against the book root,
 * refusing anything that would point outside it.
 */
class PathResolver
{
    /**
     * Normalizes a path relative to the book root, using "/" as separator.
     *
     * @throws Exception If the path is absolute or escapes the book root.
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
            throw new Exception("Path resolves outside the EPUB: {$path}");
        }

        $segments = [];
        foreach (preg_split('#[/\\\\]+#', $path) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    throw new Exception("Path resolves outside the EPUB: {$path}");
                }
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new Exception("Path resolves outside the EPUB: {$path}");
        }

        return implode('/', $segments);
    }

    /**
     * Joins a book-relative path onto the directory holding the extracted book.
     *
     * @throws Exception If the path is absolute or escapes the book root.
     */
    public function resolve(string $rootDirectory, string $path): string
    {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $this->normalize($path));

        return rtrim($rootDirectory, '/\\') . DIRECTORY_SEPARATOR . $relative;
    }
}
