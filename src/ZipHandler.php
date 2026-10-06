<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\PathResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class ZipHandler
{
    /**
     * Entries larger than this are checked against the compression ratio limit.
     */
    private const int RATIO_CHECK_THRESHOLD = 1024 * 1024;

    private const int CHUNK_SIZE = 65536;

    /**
     * @param int $maxEntries Maximum number of entries an archive may contain.
     * @param int $maxUncompressedBytes Maximum total size of the extracted contents.
     * @param int $maxCompressionRatio Maximum uncompressed/compressed ratio for a single large entry.
     */
    public function __construct(
        private readonly int $maxEntries = 10_000,
        private readonly int $maxUncompressedBytes = 1024 * 1024 * 1024,
        private readonly int $maxCompressionRatio = 100,
        private readonly PathResolver $paths = new PathResolver()
    ) {
    }

    /**
     * Extracts a ZIP file to a specified directory.
     *
     * The archive is treated as untrusted: entry names must stay inside the
     * destination, and the entry count, total size and compression ratio are
     * limited while the data is written (declared sizes are not trusted).
     *
     * @param string $zipFilePath The path to the ZIP file.
     * @param string $destination The directory where the contents should be extracted.
     *
     * @throws ZipException If the extraction fails or a limit is exceeded.
     */
    public function extract(string $zipFilePath, string $destination): void
    {
        if (! file_exists($zipFilePath)) {
            throw new ZipException("ZIP file does not exist: {$zipFilePath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFilePath) !== true) {
            throw new ZipException("Failed to open ZIP file: {$zipFilePath}");
        }

        try {
            if ($zip->numFiles > $this->maxEntries) {
                throw new ZipException(
                    "ZIP file has too many entries ({$zip->numFiles} > {$this->maxEntries}): {$zipFilePath}"
                );
            }

            $extractedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new ZipException("Failed to read entry {$index} of ZIP file: {$zipFilePath}");
                }

                $extractedBytes += $this->extractEntry($zip, $index, $stat['name'], $stat['comp_size'], $destination, $extractedBytes);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Extracts one entry and returns the number of bytes written.
     *
     * @throws ZipException
     */
    private function extractEntry(
        ZipArchive $zip,
        int $index,
        string $name,
        int $compressedSize,
        string $destination,
        int $extractedBytes
    ): int {
        try {
            $target = $this->paths->resolve($destination, $name);
        } catch (InvalidEpubException $exception) {
            throw new ZipException("ZIP entry resolves outside the EPUB: {$name}", 0, $exception);
        }

        if (str_ends_with($name, '/') || str_ends_with($name, '\\')) {
            $this->ensureDirectory($target);

            return 0;
        }

        $this->ensureDirectory(dirname($target));

        // Hostile archives make these calls emit warnings; report them as exceptions instead.
        $input = @$zip->getStreamIndex($index);
        if ($input === false) {
            throw new ZipException("Failed to read ZIP entry: {$name} ({$zip->getStatusString()})");
        }

        $output = @fopen($target, 'wb');
        if ($output === false) {
            fclose($input);
            throw new ZipException("Failed to create file for ZIP entry: {$name}" . $this->lastError());
        }

        $written = 0;

        try {
            while (! feof($input)) {
                $chunk = @fread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new ZipException("Failed to read ZIP entry: {$name}" . $this->lastError());
                }

                $written += strlen($chunk);

                if ($extractedBytes + $written > $this->maxUncompressedBytes) {
                    throw new ZipException(
                        "ZIP file exceeds the maximum uncompressed size of {$this->maxUncompressedBytes} bytes"
                    );
                }

                if ($written > self::RATIO_CHECK_THRESHOLD && $written > max(1, $compressedSize) * $this->maxCompressionRatio) {
                    throw new ZipException(
                        "ZIP entry exceeds the maximum compression ratio of {$this->maxCompressionRatio}: {$name}"
                    );
                }

                if (fwrite($output, $chunk) === false) {
                    throw new ZipException("Failed to write ZIP entry: {$name}");
                }
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        return $written;
    }

    /**
     * @throws ZipException
     */
    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new ZipException("Failed to create directory: {$directory}" . $this->lastError());
        }
    }

    /**
     * Formats the last suppressed PHP error, e.g. " (Zlib error: data error)".
     */
    private function lastError(): string
    {
        $error = error_get_last();

        return $error === null ? '' : " ({$error['message']})";
    }

    /**
     * Compresses a directory into a ZIP file.
     *
     * @param string $source The directory to compress.
     * @param string $zipFilePath The path where the ZIP file should be created.
     *
     * @throws ZipException If the compression fails.
     */
    public function compress(string $source, string $zipFilePath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ZipException("Failed to create ZIP file: {$zipFilePath}");
        }

        $realSource = realpath($source);
        if ($realSource === false) {
            throw new ZipException("Invalid source directory: {$source}");
        }

        // OCF: "mimetype" must be the first entry and must be stored uncompressed.
        $mimetypePath = $realSource . DIRECTORY_SEPARATOR . 'mimetype';
        if (is_file($mimetypePath)) {
            $zip->addFile($mimetypePath, 'mimetype');
            $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
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

            // ZIP entry names always use "/", regardless of the host OS.
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen($realSource) + 1));

            if ($relativePath === 'mimetype') {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($filePath, $relativePath);
            }
        }

        if (! $zip->close()) {
            throw new ZipException("Failed to finalize ZIP file: {$zipFilePath}");
        }
    }
}
