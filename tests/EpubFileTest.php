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

    /**
     * @param array<string, string> $expectedMetadata
     */
    #[DataProvider('epubFileProvider')]
    public function testLoadEpub(string $epubPath, bool $shouldLoad, array $expectedMetadata): void
    {
        if (!$shouldLoad) {
            $this->expectException(Exception::class);
        }

        $epubFile = new EpubFile($epubPath);
        $epubFile->load();

        if ($shouldLoad) {
            $metadata = $epubFile->getMetadata();
            $this->assertNotNull($metadata);
            $this->assertSame($expectedMetadata['title'], $metadata->getTitle());
            $this->assertSame($expectedMetadata['authors'], $metadata->getAuthors());
            $this->assertSame($expectedMetadata['description'], $metadata->getDescription());
            $this->assertSame($expectedMetadata['publisher'], $metadata->getPublisher());
            $this->assertSame($expectedMetadata['language'], $metadata->getLanguage());
        }
    }

    #[DataProvider('epubFileProvider')]
    public function testSaveEpub(string $epubPath, bool $shouldLoad): void
    {
        if (!$shouldLoad) {
            $this->expectException(Exception::class);
        }

        $epubFile = new EpubFile($epubPath);
        $epubFile->load();
        $epubFile->save($this->outputEpubPath);

        if ($shouldLoad) {
            $this->assertFileExists($this->outputEpubPath);
        }
    }

    public static function epubFileProvider(): Iterator
    {
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub', true, [
            'title' => 'Fundamental Accessibility Tests: Basic Functionality',
            'authors' => [
                'DAISY Consortium'
            ],
            'description' => 'These tests include starting the reading system and opening the titles, navigating the content, searching, and using bookmarks and notes.',
            'publisher' => '',
            'language' => 'en',
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_1.epub', true, [
            'title' => 'Anonim',
            'authors' => [
                'Bancuri Cu John'
            ],
            'description' => '',
            'publisher' => '',
            'language' => 'ro',
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_2.epub', true, [
            'title' => 'Brave New World',
            'authors' => [
                'Aldous Huxley'
            ],
            'description' => '',
            'publisher' => 'epubBooks Classics',
            'language' => 'en',
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_3.epub', true, [
            'title' => 'King of the Range',
            'authors' => [
                'Frederick Schiller Faust (as Max Brand)'
            ],
            'description' => '',
            'publisher' => 'Distributed Proofreaders Canada',
            'language' => 'en',
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'invalid.epub', false, [
            'title' => '',
            'authors' => [],
            'description' => '',
            'publisher' => '',
            'language' => '',
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'nonexistent.epub', false, [
            'title' => '',
            'authors' => [],
            'description' => '',
            'publisher' => '',
            'language' => '',
        ]];
    }

    public function testSaveWithoutLoadThrowsException(): void
    {
        $this->expectException(Exception::class);

        $epubFile = new EpubFile(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub', new NullLogger());
        $epubFile->save($this->outputEpubPath);
    }
}
