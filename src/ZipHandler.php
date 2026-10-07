<?php

declare(strict_types=1);

namespace PhpEpub;

use Normalizer;
use PhpEpub\Util\PathResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

class ZipHandler
{
    /**
     * Entries larger than this are checked against the compression ratio limit.
     */
    private const int RATIO_CHECK_THRESHOLD = 1024 * 1024;

    private const int CHUNK_SIZE = 65536;

    /**
     * Modification time of every saved entry, so archives do not depend on when the book was
     * extracted or edited. 1980-01-02 UTC: ZIP stores local time and cannot go before 1980-01-01,
     * so this stays valid in every time zone.
     */
    private const int FIXED_MTIME = 315619200;

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
            $files = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new ZipException("Failed to read entry {$index} of ZIP file: {$zipFilePath}");
                }

                $extractedBytes += $this->extractEntry($zip, $index, $stat['name'], $stat['comp_size'], $destination, $extractedBytes, $files);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Extracts one entry and returns the number of bytes written.
     *
     * @param array<string, string> $files The file entries extracted so far, keyed by their case-folded target.
     *
     * @throws ZipException
     */
    private function extractEntry(
        ZipArchive $zip,
        int $index,
        string $name,
        int $compressedSize,
        string $destination,
        int $extractedBytes,
        array &$files
    ): int {
        try {
            $target = $this->paths->resolve($destination, $name);
        } catch (InvalidEpubException $exception) {
            throw new ZipException("ZIP entry resolves outside the EPUB: {$name}", 0, $exception);
        }

        $this->assertWritableName($name);

        if (str_ends_with($name, '/') || str_ends_with($name, '\\')) {
            $this->ensureDirectory($target);

            return 0;
        }

        // OCF requires names that are unique after case folding: on case-insensitive file systems
        // (Windows, macOS) one entry would silently replace the other.
        $folded = $this->fold($target);
        if (isset($files[$folded])) {
            throw new ZipException("ZIP entries differ only in case: {$files[$folded]} and {$name}");
        }

        $files[$folded] = $name;

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
     * Folds a path the way case-insensitive file systems compare names: Unicode case folding and
     * NFC normalization when mbstring and intl are available, ASCII lower-casing otherwise
     * (and for names that are not valid UTF-8).
     */
    private function fold(string $path): string
    {
        $path = class_exists(Normalizer::class) ? (Normalizer::normalize($path) ?: $path) : $path;

        return function_exists('mb_convert_case') && mb_check_encoding($path, 'UTF-8')
            ? mb_convert_case($path, MB_CASE_FOLD)
            : strtolower($path);
    }

    /**
     * Refuses entry names the current OS cannot create, so the failure names the entry
     * instead of surfacing as a misleading "Permission denied". Other systems keep accepting them.
     *
     * @throws ZipException
     */
    private function assertWritableName(string $name): void
    {
        if (! $this->isWindows()) {
            return;
        }

        foreach (preg_split('#[/\\\\]#', $name) ?: [] as $segment) {
            $problem = match (true) {
                preg_match('/[<>:"|?*\x00-\x1f]/', $segment) === 1 => 'it contains a character Windows does not allow',
                preg_match('/[. ]$/', $segment) === 1 => 'a name cannot end with a dot or a space on Windows',
                preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $segment) === 1 => 'it is a reserved device name on Windows',
                default => null,
            };

            if ($problem !== null) {
                throw new ZipException("ZIP entry cannot be written on this system ({$problem}): {$name}");
            }
        }
    }

    /**
     * Whether extraction runs on Windows (overridable, so tests can cover both rule sets on any OS).
     */
    protected function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
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
        $realSource = realpath($source);
        if ($realSource === false) {
            throw new ZipException("Invalid source directory: {$source}");
        }

        // Written next to the target and moved into place when complete, so a failed save leaves
        // neither a partial archive nor damage to the file it would replace.
        $temporary = $zipFilePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $zip = $this->createArchive();
        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ZipException("Failed to create ZIP file: {$zipFilePath}");
        }

        $closed = false;

        try {
            $this->addEntries($zip, $realSource);

            if (! $zip->close()) {
                throw new ZipException("Failed to finalize ZIP file: {$zipFilePath} ({$zip->getStatusString()})");
            }

            $closed = true;

            // libzip writes nothing for an archive without entries.
            if (! is_file($temporary)) {
                throw new ZipException("Failed to finalize ZIP file: nothing to compress in {$source}");
            }

            if (! @rename($temporary, $zipFilePath)) {
                throw new ZipException("Failed to finalize ZIP file: {$zipFilePath}" . $this->lastError());
            }
        } catch (Throwable $throwable) {
            if (! $closed) {
                // Discard the pending entries, or the archive is written when the object is destroyed.
                // A close() that failed may already have released it (it does on Linux and macOS).
                try {
                    $zip->unchangeAll();
                    @$zip->close();
                } catch (\ValueError) {
                }
            }
            @unlink($temporary);

            throw $throwable;
        }
    }

    /**
     * The archive compress() fills (overridable, so tests can make single operations fail).
     */
    protected function createArchive(): ZipArchive
    {
        return new ZipArchive();
    }

    /**
     * @throws ZipException
     */
    private function addEntries(ZipArchive $zip, string $realSource): void
    {
        // OCF: "mimetype" must be the first entry and must be stored uncompressed.
        $mimetypePath = $realSource . DIRECTORY_SEPARATOR . 'mimetype';
        if (is_file($mimetypePath)) {
            $this->assertDone($zip->addFile($mimetypePath, 'mimetype'), $zip, 'add', 'mimetype');
            $this->assertDone($zip->setCompressionName('mimetype', ZipArchive::CM_STORE), $zip, 'store', 'mimetype');
            $this->normalizeEntry($zip, 'mimetype', false);
        }

        // Entries are added in a fixed order with fixed times and permissions, so saving
        // the same book twice (on any OS) produces the same bytes.
        foreach ($this->entries($realSource) as $relativePath => $filePath) {
            $isDirectory = is_dir($filePath);
            $added = $isDirectory ? $zip->addEmptyDir($relativePath) : $zip->addFile($filePath, $relativePath);
            $this->assertDone($added, $zip, 'add', $relativePath);

            $this->normalizeEntry($zip, $isDirectory ? $relativePath . '/' : $relativePath, $isDirectory);
        }
    }

    /**
     * @throws ZipException If a ZipArchive call failed.
     */
    private function assertDone(bool $succeeded, ZipArchive $zip, string $action, string $name): void
    {
        if (! $succeeded) {
            throw new ZipException("Failed to {$action} ZIP entry: {$name} ({$zip->getStatusString()})");
        }
    }

    /**
     * Files and directories below $root (except "mimetype"), as ZIP entry name => path, sorted by name.
     *
     * @return array<string, string>
     */
    private function entries(string $root): array
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $entries = [];
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            // ZIP entry names always use "/", regardless of the host OS.
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen($root) + 1));
            if ($relativePath !== 'mimetype') {
                $entries[$relativePath] = $filePath;
            }
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * Gives an entry a fixed modification time and Unix permissions (0644 files, 0755 directories).
     */
    private function normalizeEntry(ZipArchive $zip, string $name, bool $isDirectory): void
    {
        $this->assertDone($zip->setMtimeName($name, self::FIXED_MTIME), $zip, 'set the time of', $name);
        $mode = $isDirectory ? 040755 : 0100644;
        $this->assertDone($zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16), $zip, 'set the permissions of', $name);
    }
}
