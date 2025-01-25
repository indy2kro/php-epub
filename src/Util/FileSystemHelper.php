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
     */
    public function deleteDirectory(string $dir): bool
    {
        if (! is_dir($dir)) {
            return true;
        }

        $files = scandir($dir);

        if ($files === false) {
            return false;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($filePath) ? $this->deleteDirectory($filePath) : unlink($filePath);
        }

        return rmdir($dir);
    }
}
