<?php

declare(strict_types=1);

namespace PhpEpub;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class ZipHandler
{
    /**
     * Extracts a ZIP file to a specified directory.
     *
     * @param string $zipFilePath The path to the ZIP file.
     * @param string $destination The directory where the contents should be extracted.
     *
     * @throws Exception If the extraction fails.
     */
    public function extract(string $zipFilePath, string $destination): void
    {
        if (! file_exists($zipFilePath)) {
            throw new Exception("ZIP file does not exist: {$zipFilePath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFilePath) !== true) {
            throw new Exception("Failed to open ZIP file: {$zipFilePath}");
        }

        if (! $zip->extractTo($destination)) {
            $zip->close();
            throw new Exception("Failed to extract ZIP file to: {$destination}");
        }

        $zip->close();
    }

    /**
     * Compresses a directory into a ZIP file.
     *
     * @param string $source The directory to compress.
     * @param string $zipFilePath The path where the ZIP file should be created.
     *
     * @throws Exception If the compression fails.
     */
    public function compress(string $source, string $zipFilePath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Failed to create ZIP file: {$zipFilePath}");
        }

        $realSource = realpath($source);
        if ($realSource === false) {
            throw new Exception("Invalid source directory: {$source}");
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realSource, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $relativePath = substr($filePath, strlen($realSource) + 1);

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($filePath, $relativePath);
            }
        }

        if (! $zip->close()) {
            throw new Exception("Failed to finalize ZIP file: {$zipFilePath}");
        }
    }
}
