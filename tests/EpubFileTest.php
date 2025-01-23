<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Iterator;

class EpubFileTest extends TestCase
{
    private string $outputEpubPath;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->outputEpubPath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'output.epub';
        $this->tempDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;

        // Ensure the output and temp directories exist
        if (!is_dir(dirname($this->outputEpubPath))) {
            mkdir(dirname($this->outputEpubPath), 0777, true);
        }
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any files or directories created during tests
        if (file_exists($this->outputEpubPath)) {
            unlink($this->outputEpubPath);
        }

        if (is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
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

    #[DataProvider('epubFileProvider')]
    public function testLoadEpub(string $epubPath, bool $shouldLoad, bool $isValid): void
    {
        if (!$shouldLoad) {
            $this->expectException(Exception::class);
        }

        $epubFile = new EpubFile($epubPath, new NullLogger());
        $epubFile->load();

        if ($shouldLoad) {
            $this->assertDirectoryExists($this->tempDir);
        }
    }

    #[DataProvider('epubFileProvider')]
    public function testValidateEpub(string $epubPath, bool $shouldLoad, bool $isValid): void
    {
        if (!$shouldLoad) {
            $this->expectException(Exception::class);
        }

        $epubFile = new EpubFile($epubPath, new NullLogger());
        $epubFile->load();

        $validationResult = $epubFile->validate();
        $this->assertSame($isValid, $validationResult);
    }

    #[DataProvider('epubFileProvider')]
    public function testSaveEpub(string $epubPath, bool $shouldLoad, bool $isValid): void
    {
        if (!$shouldLoad) {
            $this->expectException(Exception::class);
        }

        $epubFile = new EpubFile($epubPath, new NullLogger());
        $epubFile->load();
        $epubFile->save($this->outputEpubPath);

        if ($shouldLoad) {
            $this->assertFileExists($this->outputEpubPath);
        }
    }

    public static function epubFileProvider(): Iterator
    {
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub', true, true];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'invalid.epub', true, false];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'nonexistent.epub', false, false];
    }

    public function testSaveWithoutLoadThrowsException(): void
    {
        $this->expectException(Exception::class);

        $epubFile = new EpubFile(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub', new NullLogger());
        $epubFile->save($this->outputEpubPath);
    }

    public function testValidateWithoutLoadThrowsException(): void
    {
        $this->expectException(Exception::class);

        $epubFile = new EpubFile(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub', new NullLogger());
        $epubFile->validate();
    }
}
