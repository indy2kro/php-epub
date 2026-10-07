<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ZipException;
use PhpEpub\ZipHandler;
use PHPUnit\Framework\TestCase;

final class EpubStringStreamTest extends TestCase
{
    private string $tmpDir;

    /**
     * @var list<string>
     */
    private array $scratchBefore = [];

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'strings';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }

        $this->scratchBefore = $this->scratchEntries();
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testOpenStringLoadsTheBook(): void
    {
        $epubFile = EpubFile::openString($this->bookData());

        $this->assertSame('Valid Book', $epubFile->getMetadata()->getTitle());
        $epubFile->cleanup();
        $this->assertSame($this->scratchBefore, $this->scratchEntries());
    }

    public function testOpenStreamReadsFromTheCurrentPosition(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, 'ignored prefix' . $this->bookData());
        fseek($stream, strlen('ignored prefix'));

        $epubFile = EpubFile::openStream($stream);

        $this->assertSame('Valid Book', $epubFile->getMetadata()->getTitle());
        // The caller keeps the stream.
        $this->assertTrue(is_resource($stream));
        fclose($stream);
    }

    public function testOpenStreamRejectsAValueThatIsNotAStream(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('stream');

        EpubFile::openStream($this->notAStream());
    }

    public function testOpenStreamReportsAStreamItCannotRead(): void
    {
        $stream = fopen('php://output', 'wb');
        $this->assertIsResource($stream);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to read the EPUB stream');

        try {
            EpubFile::openStream($stream);
        } finally {
            fclose($stream);
            $this->assertSame($this->scratchBefore, $this->scratchEntries());
        }
    }

    public function testOpenStringRejectsDataThatIsNotAnEpubAndCleansUp(): void
    {
        try {
            EpubFile::openString('definitely not a zip file');
            $this->fail('Expected an exception.');
        } catch (ZipException) {
            $this->assertSame($this->scratchBefore, $this->scratchEntries());
        }
    }

    public function testOpenStringAppliesTheZipLimits(): void
    {
        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('too many entries');

        EpubFile::openString($this->bookData(), new ZipHandler(maxEntries: 2));
    }

    public function testOpenStreamAppliesTheZipLimits(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, $this->bookData());
        rewind($stream);

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('exceeds the maximum uncompressed size');

        try {
            EpubFile::openStream($stream, new ZipHandler(maxUncompressedBytes: 100));
        } finally {
            fclose($stream);
        }
    }

    public function testSaveToStringRoundTrips(): void
    {
        $epubFile = EpubFile::openString($this->bookData());
        $epubFile->getMetadata()->setTitle('Edited in memory');

        $data = $epubFile->saveToString();

        $this->assertStringStartsWith('PK', $data);
        $this->assertSame('Edited in memory', EpubFile::openString($data)->getMetadata()->getTitle());
        $this->assertSame($this->scratchBefore, $this->scratchEntries());
    }

    public function testSaveToStreamWritesAtTheCurrentPositionAndKeepsTheStreamOpen(): void
    {
        $epubFile = EpubFile::openString($this->bookData());
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, 'header');

        $epubFile->saveToStream($stream);

        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);
        $this->assertStringStartsWith('header' . 'PK', $contents);
        $this->assertSame('Valid Book', EpubFile::openString(substr($contents, strlen('header')))->getMetadata()->getTitle());
        $this->assertSame($this->scratchBefore, $this->scratchEntries());
    }

    public function testSaveToStreamRejectsAValueThatIsNotAStream(): void
    {
        $epubFile = EpubFile::openString($this->bookData());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('stream');

        $epubFile->saveToStream($this->notAStream());
    }

    public function testSaveToStreamReportsAStreamItCannotWriteTo(): void
    {
        $epubFile = EpubFile::openString($this->bookData());
        $stream = fopen('php://memory', 'rb');
        $this->assertIsResource($stream);

        try {
            $epubFile->saveToStream($stream);
            $this->fail('Expected an exception.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Failed to write the EPUB to the stream', $exception->getMessage());
        } finally {
            fclose($stream);
        }

        $this->assertSame($this->scratchBefore, $this->scratchEntries());
    }

    public function testSaveToStringRemovesItsScratchFilesWhenSavingFails(): void
    {
        $epubFile = EpubFile::openString($this->bookData());
        $epubFile->cleanup();

        try {
            $epubFile->saveToString();
            $this->fail('Expected an exception.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('must be loaded', $exception->getMessage());
        }

        $this->assertSame($this->scratchBefore, $this->scratchEntries());
    }

    public function testBooksFromFilesCanBeSavedToStringsAndStreams(): void
    {
        $path = EpubBuilder::epub3()->buildEpub($this->tmpDir . '/book.epub');
        $epubFile = EpubFile::open($path);

        $this->assertSame('Valid Book', EpubFile::openString($epubFile->saveToString())->getMetadata()->getTitle());
    }

    public function testSaveWithoutAPathNeedsOneForABookFromAString(): void
    {
        $epubFile = EpubFile::openString($this->bookData());

        try {
            $epubFile->save();
            $this->fail('Expected an exception.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('saveToString()', $exception->getMessage());
        }

        $target = $this->tmpDir . '/saved.epub';
        $epubFile->save($target);
        $this->assertSame('Valid Book', EpubFile::open($target)->getMetadata()->getTitle());
    }

    public function testLoadCannotReloadABookFromAString(): void
    {
        $epubFile = EpubFile::openString($this->bookData());
        $epubFile->getMetadata()->setTitle('Unsaved');

        try {
            $epubFile->load();
            $this->fail('Expected an exception.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('no file to load again', $exception->getMessage());
        }

        // The loaded book and its changes are kept.
        $this->assertSame('Unsaved', $epubFile->getMetadata()->getTitle());
    }

    /**
     * A closed stream: no longer a usable resource.
     *
     * @return resource
     */
    private function notAStream()
    {
        $stream = fopen('php://memory', 'rb');
        $this->assertIsResource($stream);
        fclose($stream);

        return $stream;
    }

    private function bookData(): string
    {
        return (string) file_get_contents(EpubBuilder::epub3()->buildEpub($this->tmpDir . '/source.epub'));
    }

    /**
     * @return list<string>
     */
    private function scratchEntries(): array
    {
        return array_map(basename(...), glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epubio_*') ?: []);
    }
}
