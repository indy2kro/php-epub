<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PhpEpub\Util\FileUtil;
use PhpEpub\ZipHandler;
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
        if (! is_dir($this->extractDir)) {
            mkdir($this->extractDir, 0777, true);
        }
        if (! is_dir($this->compressDir)) {
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
            FileUtil::deleteDirectory($this->extractDir);
        }

        if (is_dir($this->compressDir)) {
            FileUtil::deleteDirectory($this->compressDir);
        }
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
        $this->expectExceptionMessage('Failed to open ZIP file:');

        $zipHandler = new ZipHandler();
        $zipHandler->extract($this->invalidZipPath, $this->extractDir);
    }

    public function testExtractNonExistentZipThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('ZIP file does not exist:');

        $zipHandler = new ZipHandler();
        $zipHandler->extract(__DIR__ . DIRECTORY_SEPARATOR . 'nonexistent.zip', $this->extractDir);
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
        $this->expectExceptionMessage('Invalid source directory:');

        $zipHandler = new ZipHandler();
        $zipHandler->compress(__DIR__ . DIRECTORY_SEPARATOR . 'nonexistent', $this->outputZipPath);
    }

    public function testCompressNonExistentOutputDirectoryThrowsException(): void
    {
        // Create a sample file to compress
        $sampleFilePath = $this->compressDir . DIRECTORY_SEPARATOR . 'sample.txt';
        file_put_contents($sampleFilePath, 'Sample content');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to create ZIP file:');

        $zipHandler = new ZipHandler();
        @$zipHandler->compress($this->compressDir, __DIR__ . DIRECTORY_SEPARATOR . 'nonexistent' . DIRECTORY_SEPARATOR . 'output.zip');
    }
}
