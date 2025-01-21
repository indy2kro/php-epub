<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\ZipHandler;
use PhpEpub\Exception;
use PHPUnit\Framework\TestCase;

class ZipHandlerTest extends TestCase
{
    private string $validZipPath;
    private string $invalidZipPath;
    private string $extractDir;
    private string $compressDir;
    private string $outputZipPath;

    protected function setUp(): void
    {
        $this->validZipPath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.zip';
        $this->invalidZipPath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'invalid.zip';
        $this->extractDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'extracted';
        $this->compressDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'compress';
        $this->outputZipPath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'output.zip';

        // Ensure the directories exist
        if (!is_dir($this->extractDir)) {
            mkdir($this->extractDir, 0777, true);
        }
        if (!is_dir($this->compressDir)) {
            mkdir($this->compressDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any files or directories created during tests
        if (file_exists($this->outputZipPath)) {
            unlink($this->outputZipPath);
        }

        if (is_dir($this->extractDir)) {
            $this->deleteDirectory($this->extractDir);
        }

        if (is_dir($this->compressDir)) {
            $this->deleteDirectory($this->compressDir);
        }
    }

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

    public function testExtractValidZip(): void
    {
        $zipHandler = new ZipHandler();
        $zipHandler->extract($this->validZipPath, $this->extractDir);

        $this->assertDirectoryExists($this->extractDir);
        $this->assertNotEmpty(scandir($this->extractDir));
    }

    public function testExtractInvalidZipThrowsException(): void
    {
        $this->expectException(Exception::class);

        $zipHandler = new ZipHandler();
        $zipHandler->extract($this->invalidZipPath, $this->extractDir);
    }

    public function testCompressDirectory(): void
    {
        // Create a sample file to compress
        $sampleFilePath = $this->compressDir . DIRECTORY_SEPARATOR . 'sample.txt';
        file_put_contents($sampleFilePath, 'Sample content');

        $zipHandler = new ZipHandler();
        $zipHandler->compress($this->compressDir, $this->outputZipPath);

        $this->assertFileExists($this->outputZipPath);
    }

    public function testCompressNonExistentDirectoryThrowsException(): void
    {
        $this->expectException(Exception::class);

        $zipHandler = new ZipHandler();
        $zipHandler->compress(__DIR__ . DIRECTORY_SEPARATOR . 'nonexistent', $this->outputZipPath);
    }
}
