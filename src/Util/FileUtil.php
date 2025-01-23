<?php

declare(strict_types=1);

namespace PhpEpub\Util;

class FileUtil
{
    /**
     * Recursively deletes a directory and its contents.
     */
    public static function deleteDirectory(string $dir): bool
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
            is_dir($filePath) ? self::deleteDirectory($filePath) : unlink($filePath);
        }

        return rmdir($dir);
    }
}
