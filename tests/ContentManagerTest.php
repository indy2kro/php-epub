<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\ContentManager;
use PhpEpub\Exception;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

final class ContentManagerTest extends TestCase
{
    private string $contentDir;
    private string $sampleFilePath;
    private string $outsidePath;
    private FileSystemHelper $fileSystemHelper;

    protected function setUp(): void
    {
        $this->fileSystemHelper = new FileSystemHelper();
        $this->contentDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'content';
        $this->sampleFilePath = $this->contentDir . DIRECTORY_SEPARATOR . 'sample.txt';
        $this->outsidePath = dirname($this->contentDir) . DIRECTORY_SEPARATOR . 'outside.txt';

        // Ensure the content directory exists
        if (! is_dir($this->contentDir)) {
            mkdir($this->contentDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any files or directories created during tests
        if (file_exists($this->outsidePath)) {
            unlink($this->outsidePath);
        }

        if (is_dir($this->contentDir)) {
            $this->fileSystemHelper->deleteDirectory($this->contentDir);
        }
    }

    public function testAddContent(): void
    {
        $contentManager = new ContentManager($this->contentDir);
        $contentManager->addContent('sample.txt', 'Sample content');

        $this->assertFileExists($this->sampleFilePath);
        $this->assertStringEqualsFile($this->sampleFilePath, 'Sample content');
    }

    public function testUpdateContent(): void
    {
        file_put_contents($this->sampleFilePath, 'Old content');

        $contentManager = new ContentManager($this->contentDir);
        $contentManager->updateContent('sample.txt', 'Updated content');

        $this->assertStringEqualsFile($this->sampleFilePath, 'Updated content');
    }

    public function testDeleteContent(): void
    {
        file_put_contents($this->sampleFilePath, 'Content to delete');

        $contentManager = new ContentManager($this->contentDir);
        $contentManager->deleteContent('sample.txt');

        $this->assertFileDoesNotExist($this->sampleFilePath);
    }

    public function testGetContent(): void
    {
        file_put_contents($this->sampleFilePath, 'Content to read');

        $contentManager = new ContentManager($this->contentDir);
        $content = $contentManager->getContent('sample.txt');

        $this->assertSame('Content to read', $content);
    }

    public function testGetContentList(): void
    {
        file_put_contents($this->sampleFilePath, 'Sample content');
        file_put_contents($this->contentDir . DIRECTORY_SEPARATOR . 'another.txt', 'Another content');

        $contentManager = new ContentManager($this->contentDir);
        $contentList = $contentManager->getContentList();

        $this->assertCount(2, $contentList);
        $this->assertContains($this->sampleFilePath, $contentList);
        $this->assertContains($this->contentDir . DIRECTORY_SEPARATOR . 'another.txt', $contentList);
    }

    public function testGetContentRejectsPathOutsideTheBook(): void
    {
        file_put_contents($this->outsidePath, 'secret');
        $contentManager = new ContentManager($this->contentDir);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('outside the EPUB');

        $contentManager->getContent('../outside.txt');
    }

    public function testAddContentRejectsPathOutsideTheBook(): void
    {
        $contentManager = new ContentManager($this->contentDir);

        try {
            $contentManager->addContent('../outside.txt', 'planted');
            $this->fail('Expected an exception for a path outside the book.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('outside the EPUB', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->outsidePath);
    }

    public function testUpdateContentRejectsPathOutsideTheBook(): void
    {
        file_put_contents($this->outsidePath, 'original');
        $contentManager = new ContentManager($this->contentDir);

        try {
            $contentManager->updateContent('sub/../../outside.txt', 'changed');
            $this->fail('Expected an exception for a path outside the book.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('outside the EPUB', $exception->getMessage());
        }

        $this->assertStringEqualsFile($this->outsidePath, 'original');
    }

    public function testDeleteContentRejectsAbsolutePath(): void
    {
        file_put_contents($this->outsidePath, 'keep me');
        $contentManager = new ContentManager($this->contentDir);

        try {
            $contentManager->deleteContent($this->outsidePath);
            $this->fail('Expected an exception for an absolute path.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('outside the EPUB', $exception->getMessage());
        }

        $this->assertFileExists($this->outsidePath);
    }

    public function testGetContentOfADirectoryThrowsOnEveryOs(): void
    {
        mkdir($this->contentDir . DIRECTORY_SEPARATOR . 'folder');
        $contentManager = new ContentManager($this->contentDir);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Content file does not exist:');

        $contentManager->getContent('folder');
    }

    public function testUpdateContentOfADirectoryThrows(): void
    {
        mkdir($this->contentDir . DIRECTORY_SEPARATOR . 'folder');
        $contentManager = new ContentManager($this->contentDir);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Content file does not exist:');

        $contentManager->updateContent('folder', 'text');
    }

    public function testDeleteContentOfADirectoryThrowsAndKeepsIt(): void
    {
        mkdir($this->contentDir . DIRECTORY_SEPARATOR . 'folder');
        $contentManager = new ContentManager($this->contentDir);

        try {
            $contentManager->deleteContent('folder');
            $this->fail('Expected an exception for a directory path.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Content file does not exist:', $exception->getMessage());
        }

        $this->assertDirectoryExists($this->contentDir . DIRECTORY_SEPARATOR . 'folder');
    }

    public function testUpdateReadOnlyContentThrows(): void
    {
        file_put_contents($this->sampleFilePath, 'locked');
        chmod($this->sampleFilePath, 0444);
        $contentManager = new ContentManager($this->contentDir);

        try {
            if (is_writable($this->sampleFilePath)) {
                $this->markTestSkipped('Read-only files are writable here (e.g. running as root).');
            }

            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Failed to update content in:');

            $contentManager->updateContent('sample.txt', 'changed');
        } finally {
            chmod($this->sampleFilePath, 0644);
        }
    }

    public function testDeleteContentReportsAFileItCannotDelete(): void
    {
        file_put_contents($this->sampleFilePath, 'locked');
        // Windows refuses to delete a read-only file; POSIX refuses to unlink from a read-only directory.
        chmod($this->sampleFilePath, 0444);
        chmod($this->contentDir, 0555);
        $contentManager = new ContentManager($this->contentDir);

        try {
            if (DIRECTORY_SEPARATOR === '/' && is_writable($this->contentDir)) {
                $this->markTestSkipped('Read-only directories are writable here (e.g. running as root).');
            }

            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Failed to delete content from:');

            $contentManager->deleteContent('sample.txt');
        } finally {
            chmod($this->contentDir, 0777);
            chmod($this->sampleFilePath, 0644);
        }
    }

    public function testGetContentReportsAFileItCannotRead(): void
    {
        file_put_contents($this->sampleFilePath, 'secret');
        // POSIX: no read permission. Windows: an exclusive lock blocks other readers (chmod only sets read-only there).
        $handle = null;
        if (DIRECTORY_SEPARATOR === '\\') {
            $handle = fopen($this->sampleFilePath, 'r+');
            $this->assertIsResource($handle);
            flock($handle, LOCK_EX);
        } else {
            chmod($this->sampleFilePath, 0000);
        }

        $contentManager = new ContentManager($this->contentDir);

        try {
            if (DIRECTORY_SEPARATOR === '/' && is_readable($this->sampleFilePath)) {
                $this->markTestSkipped('Unreadable files are readable here (e.g. running as root).');
            }

            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Failed to read content from:');

            $contentManager->getContent('sample.txt');
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            chmod($this->sampleFilePath, 0644);
        }
    }

    public function testAddContentToNonExistentDirectoryThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Content directory does not exist:');

        $contentManager = new ContentManager(__DIR__ . DIRECTORY_SEPARATOR . 'nonexistent');
        $contentManager->addContent('sample.txt', 'Sample content');
    }

    public function testUpdateNonExistentContentThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Content file does not exist:');

        $contentManager = new ContentManager($this->contentDir);
        $contentManager->updateContent('nonexistent.txt', 'Content');
    }

    public function testDeleteNonExistentContentThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Content file does not exist:');

        $contentManager = new ContentManager($this->contentDir);
        $contentManager->deleteContent('nonexistent.txt');
    }

    public function testGetNonExistentContentThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Content file does not exist:');

        $contentManager = new ContentManager($this->contentDir);
        $contentManager->getContent('nonexistent.txt');
    }

    public function testConstructorThrowsExceptionForNonExistentDirectory(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Content directory does not exist:');

        $nonExistentDir = __DIR__ . DIRECTORY_SEPARATOR . 'non_existent_dir';
        new ContentManager($nonExistentDir);
    }

    public function testAddContentFailsWithInvalidPath(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to add content to:');

        // A directory already exists where the file should be written
        mkdir($this->contentDir . DIRECTORY_SEPARATOR . 'test');

        $contentManager = new ContentManager($this->contentDir);
        $contentManager->addContent('test', 'Sample content');
    }

    public function testUpdateContentFailsWithNonexistentFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Content file does not exist:');

        $contentManager = new ContentManager($this->contentDir);
        $contentManager->updateContent('nonexistent.txt', 'New content');
    }

    public function testDeleteContentFailsWithNonexistentFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Content file does not exist:');

        $contentManager = new ContentManager($this->contentDir);
        $contentManager->deleteContent('nonexistent.txt');
    }

    public function testGetContentFailsWithNonexistentFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Content file does not exist:');

        $contentManager = new ContentManager($this->contentDir);
        $contentManager->getContent('nonexistent.txt');
    }
}
