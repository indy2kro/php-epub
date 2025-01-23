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
            $this->assertSame($expectedMetadata['subject'], $metadata->getSubject());
            $this->assertSame($expectedMetadata['date'], $metadata->getDate());
            $this->assertSame($expectedMetadata['identifiers'], $metadata->getIdentifiers());
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
            'subject' => 'basic-functionality',
            'date' => '',
            'identifiers' => [
                'com.github.epub-testsuite.epub30-test-0301-2.0.0',
                '9781003410126'
            ],
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_1.epub', true, [
            'title' => 'Anonim',
            'authors' => [
                'Bancuri Cu John'
            ],
            'description' => '',
            'publisher' => '',
            'language' => 'ro',
            'subject' => '',
            'date' => '2015-08-24',
            'identifiers' => [
                'AWP-DF47F263-F894-490D-9E8A-2492EC571534'
            ],
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_2.epub', true, [
            'title' => 'Brave New World',
            'authors' => [
                'Aldous Huxley'
            ],
            'description' => '',
            'publisher' => 'epubBooks Classics',
            'language' => 'en',
            'subject' => 'Science Fiction',
            'date' => '2014-12-29',
            'identifiers' => [
                '_simple_book'
            ],
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_3.epub', true, [
            'title' => 'King of the Range',
            'authors' => [
                'Frederick Schiller Faust (as Max Brand)'
            ],
            'description' => '',
            'publisher' => 'Distributed Proofreaders Canada',
            'language' => 'en',
            'subject' => 'fiction',
            'date' => '1974-01-14T13:30:00+00:00',
            'identifiers' => [
                '0fe42443-c7d5-4615-a5b2-cc8b07de619c'
            ],
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_4.epub', true, [
            'title' => 'EPUB 3.0 Specification',
            'authors' => [
                'EPUB 3 Working Group'
            ],
            'description' => '',
            'publisher' => '',
            'language' => 'en',
            'subject' => '',
            'date' => '',
            'identifiers' => [
                'code.google.com.epub-samples.epub30-spec'
            ],
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_5.epub', true, [
            'title' => 'Children\'s Literature',
            'authors' => [
                'Charles Madison Curry',
                'Erle Elsworth Clippinger'
            ],
            'description' => '',
            'publisher' => '',
            'language' => 'en',
            'subject' => 'Children -- Books and reading',
            'date' => '2008-05-20',
            'identifiers' => [
                'http://www.gutenberg.org/ebooks/25545'
            ],
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_6.epub', true, [
            'title' => '500 Rätsel und Rätselscherze für jung und alt / Ein Bringmichraus für Schul und Haus',
            'authors' => [
                'Joseph Frick'
            ],
            'description' => '',
            'publisher' => '',
            'language' => 'de',
            'subject' => 'Puzzles',
            'date' => '2010-02-15',
            'identifiers' => [
                'http://www.gutenberg.org/31281'
            ],
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'invalid.epub', false, [
            'title' => '',
            'authors' => [],
            'description' => '',
            'publisher' => '',
            'language' => '',
            'subject' => '',
            'date' => '',
            'identifiers' => [
                ''
            ],
        ]];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'nonexistent.epub', false, [
            'title' => '',
            'authors' => [],
            'description' => '',
            'publisher' => '',
            'language' => '',
            'subject' => '',
            'date' => '',
            'identifiers' => [
                ''
            ],
        ]];
    }

    public function testSaveWithoutLoadThrowsException(): void
    {
        $this->expectException(Exception::class);

        $epubFile = new EpubFile(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub', new NullLogger());
        $epubFile->save($this->outputEpubPath);
    }
}
