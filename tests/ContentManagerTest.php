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
        @$contentManager->addContent('test', 'Sample content');
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
