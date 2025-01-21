<?php

declare(strict_types=1);

namespace PhpEpub;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class EpubFile
{
    private ?string $tempDir = null;
    private readonly ZipHandler $zipHandler;
    private readonly Validator $validator;

    public function __construct(private readonly string $filePath, private readonly LoggerInterface $logger = new NullLogger())
    {
        $this->zipHandler = new ZipHandler();
        $this->validator = new Validator();
    }

    public function __destruct()
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
            $this->logger->info("Temporary directory {$this->tempDir} deleted.");
        }
    }

    public function load(): void
    {
        $this->logger->info("Loading EPUB file from {$this->filePath}");

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('epub_', true);
        if (! mkdir($this->tempDir) && ! is_dir($this->tempDir)) {
            throw new Exception("Failed to create temporary directory: {$this->tempDir}");
        }

        $this->zipHandler->extract($this->filePath, $this->tempDir);
        $this->logger->info("EPUB file extracted to temporary directory {$this->tempDir}");
    }

    public function save(string $filePath): void
    {
        if ($this->tempDir === null) {
            throw new Exception('EPUB file must be loaded before saving.');
        }

        $this->logger->info("Saving EPUB file to {$filePath}");
        $this->zipHandler->compress($this->tempDir, $filePath);
        $this->logger->info('EPUB file saved successfully.');
    }

    public function validate(): bool
    {
        if ($this->tempDir === null) {
            throw new Exception('EPUB file must be loaded before validation.');
        }

        $this->logger->info('Validating EPUB file structure.');
        $isValid = $this->validator->isValid($this->tempDir);

        if ($isValid) {
            $this->logger->info('EPUB file is valid.');
        } else {
            $errors = $this->validator->getErrors();
            foreach ($errors as $error) {
                $this->logger->error("Validation error: {$error}");
            }
        }

        return $isValid;
    }

    /**
     * Recursively deletes a directory and its contents.
     */
    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = scandir($dir);

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($filePath) ? $this->deleteDirectory($filePath) : unlink($filePath);
        }
        rmdir($dir);
    }
}
