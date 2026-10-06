<?php

declare(strict_types=1);

namespace PhpEpub\Util;

class FileSystemHelper
{
    public function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * @param array<int, mixed> $output
     */
    /**
     * @param array<int, mixed> $output
     * @param-out list<string> $output
     */
    public function exec(string $command, array &$output, int &$returnVar): void
    {
        exec($command, $output, $returnVar);
    }

    public function fileSize(string $path): int|false
    {
        return filesize($path);
    }

    /**
     * Recursively deletes a directory and its contents.
     *
     * Deletes as much as it can and returns false, without emitting warnings, when
     * anything could not be removed (e.g. a file locked on Windows, or a read-only directory).
     */
    public function deleteDirectory(string $dir): bool
    {
        if (! is_dir($dir)) {
            return true;
        }

        $files = @scandir($dir);

        if ($files === false) {
            return false;
        }

        $deleted = true;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $dir . DIRECTORY_SEPARATOR . $file;

            // Remove links themselves; never recurse into their targets.
            if (is_link($filePath)) {
                // On Windows a directory symlink is removed with rmdir().
                $deleted = (@unlink($filePath) || @rmdir($filePath)) && $deleted;
                continue;
            }

            $deleted = (is_dir($filePath) ? $this->deleteDirectory($filePath) : @unlink($filePath)) && $deleted;
        }

        // A directory that still has contents cannot be removed; do not try (and warn).
        return $deleted && @rmdir($dir);
    }
}
