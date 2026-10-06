<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use Iterator;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Metadata;
use PhpEpub\Spine;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class EpubFileTest extends TestCase
{
    private string $outputEpubPath;
    private string $tempEpubFilePath;
    private string $tempDir;
    private FileSystemHelper $fileSystemHelper;

    protected function setUp(): void
    {
        $this->fileSystemHelper = new FileSystemHelper();
        $this->outputEpubPath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'output.epub';
        $this->tempEpubFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'temp_epub.epub';
        $this->tempDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;

        // Ensure the output and temp directories exist
        if (! is_dir(dirname($this->outputEpubPath))) {
            mkdir(dirname($this->outputEpubPath), 0777, true);
        }
        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any files or directories created during tests
        if (file_exists($this->outputEpubPath)) {
            unlink($this->outputEpubPath);
        }

        if (file_exists($this->tempEpubFilePath)) {
            unlink($this->tempEpubFilePath);
        }

        if (is_dir($this->tempDir)) {
            $this->fileSystemHelper->deleteDirectory($this->tempDir);
        }
    }

    /**
     * @param array<string, string> $expectedMetadata
     */
    #[DataProvider('epubFileProvider')]
    public function testLoadEpub(string $epubPath, bool $shouldLoad, array $expectedMetadata): void
    {
        if (! $shouldLoad) {
            $this->expectException(Exception::class);
        }

        if ($shouldLoad) {
            $copyResult = copy($epubPath, $this->tempEpubFilePath);
            $this->assertTrue($copyResult);
        }

        $epubFile = new EpubFile($this->tempEpubFilePath);
        $epubFile->load();

        if ($shouldLoad) {
            $tempDir = $epubFile->getTempDir();
            $this->assertNotNull($tempDir);

            $metadata = $epubFile->getMetadata();
            $this->assertInstanceOf(Metadata::class, $metadata);
            $this->assertSame($expectedMetadata['title'], $metadata->getTitle());
            $this->assertSame($expectedMetadata['authors'], $metadata->getAuthors());
            $this->assertSame($expectedMetadata['description'], $metadata->getDescription());
            $this->assertSame($expectedMetadata['publisher'], $metadata->getPublisher());
            $this->assertSame($expectedMetadata['language'], $metadata->getLanguage());
            $this->assertSame($expectedMetadata['subject'], $metadata->getSubject());
            $this->assertSame($expectedMetadata['date'], $metadata->getDate());
            $this->assertSame($expectedMetadata['identifiers'], $metadata->getIdentifiers());

            $spine = $epubFile->getSpine();
            $this->assertInstanceOf(Spine::class, $spine);
            $this->assertSame($expectedMetadata['spine'], $spine->get());
        }
    }

    /**
     * @param array<string, string> $metadata
     */
    #[DataProvider('epubFileProvider')]
    public function testSaveEpub(string $epubPath, bool $shouldLoad, array $metadata): void
    {
        if (! $shouldLoad) {
            $this->expectException(Exception::class);
        }

        if ($shouldLoad) {
            $copyResult = copy($epubPath, $this->tempEpubFilePath);
            $this->assertTrue($copyResult);
        }

        $epubFile = new EpubFile($this->tempEpubFilePath);
        $epubFile->load();
        $epubFile->save();

        if ($shouldLoad) {
            $this->assertFileExists($epubPath);
        }
    }

    /**
     * @param array<string, string> $metadata
     */
    #[DataProvider('epubFileProvider')]
    public function testSaveEpubToNewFile(string $epubPath, bool $shouldLoad, array $metadata): void
    {
        if (! $shouldLoad) {
            $this->expectException(Exception::class);
        }

        if ($shouldLoad) {
            $copyResult = copy($epubPath, $this->tempEpubFilePath);
            $this->assertTrue($copyResult);
        }

        $epubFile = new EpubFile($this->tempEpubFilePath);
        $epubFile->load();

        $epubFile->save($this->outputEpubPath);

        if ($shouldLoad) {
            $this->assertFileExists($this->outputEpubPath);
        }
    }

    public function testSavedEpubIsOcfValidAndReloads(): void
    {
        $epubFile = new EpubFile(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub');
        $epubFile->load();
        $title = $epubFile->getMetadata()->getTitle();
        $epubFile->save($this->outputEpubPath);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->outputEpubPath));
        $first = $zip->statIndex(0);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertIsArray($first);
        $this->assertSame('mimetype', $first['name']);
        $this->assertSame(ZipArchive::CM_STORE, $first['comp_method']);
        $this->assertSame([], array_filter($names, static fn (string $name): bool => str_contains($name, '\\')));

        $reloaded = new EpubFile($this->outputEpubPath);
        $reloaded->load();
        $this->assertSame($title, $reloaded->getMetadata()->getTitle());
    }

    public static function epubFileProvider(): Iterator
    {
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub', true, [
            'title' => 'Fundamental Accessibility Tests: Basic Functionality',
            'authors' => [
                'DAISY Consortium',
            ],
            'description' => 'These tests include starting the reading system and opening the titles, navigating the content, searching, and using bookmarks and notes.',
            'publisher' => '',
            'language' => 'en',
            'subject' => 'basic-functionality',
            'date' => '',
            'identifiers' => [
                'com.github.epub-testsuite.epub30-test-0301-2.0.0',
                '9781003410126',
            ],
            'spine' => [
                'cover',
                'front',
                'introduction',
                'xhtml-001',
                'xhtml-002',
            ],
        ],
        ];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_1.epub', true, [
            'title' => 'Anonim',
            'authors' => [
                'Bancuri Cu John',
            ],
            'description' => '',
            'publisher' => '',
            'language' => 'ro',
            'subject' => '',
            'date' => '2015-08-24',
            'identifiers' => [
                'AWP-DF47F263-F894-490D-9E8A-2492EC571534',
            ],
            'spine' => [
                'id001',
            ],
        ],
        ];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_2.epub', true, [
            'title' => 'Brave New World',
            'authors' => [
                'Aldous Huxley',
            ],
            'description' => '',
            'publisher' => 'epubBooks Classics',
            'language' => 'en',
            'subject' => 'Science Fiction',
            'date' => '2014-12-29',
            'identifiers' => [
                '_simple_book',
            ],
            'spine' => [
                'cover',
                'htmltoc',
                'id-idp140344214732736',
                'id-idp140344213853136',
                'id-idp140344215476048',
                'id-idp140344212955984',
                'id-idp140344211917536',
                'id-idp140344214336112',
                'id-idp140344214743856',
                'id-idp140344226668640',
                'id-idp140344211589232',
                'id-idp140344214390016',
                'id-idp140344211908768',
                'id-idp140344214089072',
                'id-idp140344214517984',
                'id-idp140344213846896',
                'id-idp140344211998544',
                'id-idp140344215682448',
                'id-idp140344214734976',
                'id-idp140344256923168',
                'id-idp140344247117760',
                'id-idp140344249631104',
                'id-idp140344257533568',
                'id-idp140344214744800',
                'id-idp140344212019376',
                'id-idp140344252995472',
                'id-idp140344257345024',
                'id-idp140344256406352',
            ],
        ],
        ];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_3.epub', true, [
            'title' => 'King of the Range',
            'authors' => [
                'Frederick Schiller Faust (as Max Brand)',
            ],
            'description' => '',
            'publisher' => 'Distributed Proofreaders Canada',
            'language' => 'en',
            'subject' => 'fiction',
            'date' => '1974-01-14T13:30:00+00:00',
            'identifiers' => [
                '0fe42443-c7d5-4615-a5b2-cc8b07de619c',
            ],
            'spine' => [
                'titlepage',
                'html9',
                'html8',
                'html7',
                'html6',
                'html5',
                'html4',
                'html3',
                'html2',
                'html1',
            ],
        ],
        ];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_4.epub', true, [
            'title' => 'EPUB 3.0 Specification',
            'authors' => [
                'EPUB 3 Working Group',
            ],
            'description' => '',
            'publisher' => '',
            'language' => 'en',
            'subject' => '',
            'date' => '',
            'identifiers' => [
                'code.google.com.epub-samples.epub30-spec',
            ],
            'spine' => [
                'ttl',
                'nav',
                'term',
                'ovw',
                'pub',
                'cd',
                'mo',
                'ocf',
                'ack',
                'ref',
                'cha',
            ],
        ],
        ];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_5.epub', true, [
            'title' => 'Children\'s Literature',
            'authors' => [
                'Charles Madison Curry',
                'Erle Elsworth Clippinger',
            ],
            'description' => '',
            'publisher' => '',
            'language' => 'en',
            'subject' => 'Children -- Books and reading',
            'date' => '2008-05-20',
            'identifiers' => [
                'http://www.gutenberg.org/ebooks/25545',
            ],
            'spine' => [
                'cover',
                'nav',
                's04',
            ],
        ],
        ];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_6.epub', true, [
            'title' => '500 Rätsel und Rätselscherze für jung und alt / Ein Bringmichraus für Schul und Haus',
            'authors' => [
                'Joseph Frick',
            ],
            'description' => '',
            'publisher' => '',
            'language' => 'de',
            'subject' => 'Puzzles',
            'date' => '2010-02-15',
            'identifiers' => [
                'http://www.gutenberg.org/31281',
            ],
            'spine' => [
                'coverpage-wrapper',
                'pg-header',
                'item4',
                'item5',
                'item6',
                'item7',
                'item8',
                'pg-footer',
            ],
        ],
        ];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'invalid.epub', false, [
            'title' => '',
            'authors' => [],
            'description' => '',
            'publisher' => '',
            'language' => '',
            'subject' => '',
            'date' => '',
            'identifiers' => [
                '',
            ],
            'spine' => [
                '',
            ],
        ],
        ];
        yield [__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'nonexistent.epub', false, [
            'title' => '',
            'authors' => [],
            'description' => '',
            'publisher' => '',
            'language' => '',
            'subject' => '',
            'date' => '',
            'identifiers' => [
                '',
            ],
            'spine' => [
                '',
            ],
        ],
        ];
    }

    public function testLoadCreatesPrivateUnpredictableTempDirectory(): void
    {
        $epubFile = new EpubFile(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub');
        $epubFile->load();
        $tempDir = (string) $epubFile->getTempDir();

        $this->assertMatchesRegularExpression('/^epub_[0-9a-f]{32}$/', basename($tempDir));
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0700, fileperms($tempDir) & 0777);
        }
    }

    #[DataProvider('accessorProvider')]
    public function testAccessorsBeforeLoadThrow(string $accessor, string $message): void
    {
        $epubFile = new EpubFile(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);

        $epubFile->{$accessor}();
    }

    #[DataProvider('accessorProvider')]
    public function testAccessorsAfterCleanupThrow(string $accessor, string $message): void
    {
        $epubFile = EpubFile::open(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub');
        $epubFile->cleanup();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);

        $epubFile->{$accessor}();
    }

    #[DataProvider('accessorProvider')]
    public function testAccessorsAfterAFailedReloadThrow(string $accessor, string $message): void
    {
        copy(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub', $this->tempEpubFilePath);
        $epubFile = EpubFile::open($this->tempEpubFilePath);
        copy(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'invalid.epub', $this->tempEpubFilePath);

        try {
            $epubFile->load();
            $this->fail('Expected loading an invalid book to fail.');
        } catch (Exception) {
            // The failed load must not leave an extraction behind or keep the old book's objects.
            $this->assertNull($epubFile->getTempDir());
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);

        $epubFile->{$accessor}();
    }

    public function testCleanupReportsAnExtractionItCannotDeleteAndCanRetry(): void
    {
        $epubFile = EpubFile::open(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub');
        $tempDir = (string) $epubFile->getTempDir();
        $metaInf = $tempDir . DIRECTORY_SEPARATOR . 'META-INF';

        // Windows cannot remove a directory while a file in it is open; POSIX cannot unlink from a read-only directory.
        $handle = fopen($metaInf . DIRECTORY_SEPARATOR . 'container.xml', 'r');
        chmod($metaInf, 0500);

        try {
            if (DIRECTORY_SEPARATOR === '/' && is_writable($metaInf)) {
                $this->markTestSkipped('Read-only directories are writable here (e.g. running as root).');
            }

            try {
                $epubFile->cleanup();
                $this->fail('Expected cleanup() to report the directory it could not delete.');
            } catch (Exception $exception) {
                $this->assertStringContainsString($tempDir, $exception->getMessage());
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            chmod($metaInf, 0700);
        }

        // The book is unloaded, but the leftover directory is remembered so cleanup can be retried.
        $this->assertSame($tempDir, $epubFile->getTempDir());
        $epubFile->cleanup();
        $this->assertDirectoryDoesNotExist($tempDir);
        $this->assertNull($epubFile->getTempDir());
    }

    public function testReloadSucceedsWhenThePreviousExtractionCannotBeDeleted(): void
    {
        $epubFile = EpubFile::open(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub');
        $oldTempDir = (string) $epubFile->getTempDir();
        $metaInf = $oldTempDir . DIRECTORY_SEPARATOR . 'META-INF';

        $handle = fopen($metaInf . DIRECTORY_SEPARATOR . 'container.xml', 'r');
        chmod($metaInf, 0500);

        try {
            if (DIRECTORY_SEPARATOR === '/' && is_writable($metaInf)) {
                $this->markTestSkipped('Read-only directories are writable here (e.g. running as root).');
            }

            // The stuck old extraction must not stop the book from loading again.
            $epubFile->load();

            $this->assertNotSame($oldTempDir, $epubFile->getTempDir());
            $this->assertNotSame('', $epubFile->getMetadata()->getTitle());
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            chmod($metaInf, 0700);
            $this->fileSystemHelper->deleteDirectory($oldTempDir);
        }
    }

    public function testCloningIsRefusedAndLeavesTheOriginalUsable(): void
    {
        $epubFile = EpubFile::open(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub');

        // A clone would share the extraction: its destructor would delete the original's files.
        $cloneOf = static fn (EpubFile $original): EpubFile => clone $original;

        try {
            $copy = $cloneOf($epubFile);
            $this->fail('Expected cloning to be refused, got a ' . $copy::class . '.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('cannot be cloned', $exception->getMessage());
        }

        $this->assertDirectoryExists((string) $epubFile->getTempDir());
        $this->assertNotSame('', $epubFile->getMetadata()->getTitle());
    }

    public static function accessorProvider(): Iterator
    {
        yield ['getMetadata', 'EPUB file must be loaded before accessing metadata.'];
        yield ['getSpine', 'EPUB file must be loaded before accessing spine.'];
        yield ['getManifest', 'EPUB file must be loaded before accessing manifest.'];
        yield ['getContentManager', 'EPUB file must be loaded before accessing content manager.'];
    }

    public function testSaveWithoutLoadThrowsException(): void
    {
        $this->expectException(Exception::class);

        $epubFile = new EpubFile(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub');
        $epubFile->save($this->outputEpubPath);
    }
}
