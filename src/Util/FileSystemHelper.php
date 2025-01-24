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
}
