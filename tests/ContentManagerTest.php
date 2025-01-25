<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\ContentManager;
use PhpEpub\Exception;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

class ContentManagerTest extends TestCase
{
    private string $contentDir;
    private string $sampleFilePath;
    private FileSystemHelper $fileSystemHelper;

    protected function setUp(): void
    {
        $this->fileSystemHelper = new FileSystemHelper();
        $this->contentDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'content';
        $this->sampleFilePath = $this->contentDir . DIRECTORY_SEPARATOR . 'sample.txt';

        // Ensure the content directory exists
        if (! is_dir($this->contentDir)) {
            mkdir($this->contentDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any files or directories created during tests
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

        // Create a directory path where we expect a file
        $invalidPath = $this->contentDir . DIRECTORY_SEPARATOR . 'test' . DIRECTORY_SEPARATOR;

        $contentManager = new ContentManager($this->contentDir);
        @$contentManager->addContent($invalidPath, 'Sample content');
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
