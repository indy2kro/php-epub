<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use PhpEpub\Converters\CalibreAdapter;
use PhpEpub\Exception;
use PhpEpub\Util\FileUtil;
use PHPUnit\Framework\TestCase;

class CalibreAdapterTest extends TestCase
{
    private string $epubDirectory;
    private string $outputMobiPath;
    private string $calibrePath;

    protected function setUp(): void
    {
        $this->epubDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'epub_content';
        $this->outputMobiPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'output.mobi';
        $this->calibrePath = '/usr/bin/ebook-convert'; // Adjust this path to your Calibre installation

        if (! file_exists($this->calibrePath)) {
            $this->markTestSkipped('Calibre is not installed or the path is incorrect.');
        }

        // Ensure the directories exist
        if (! is_dir(dirname($this->outputMobiPath))) {
            mkdir(dirname($this->outputMobiPath), 0777, true);
        }

        // Create a mock EPUB content file
        if (! is_dir($this->epubDirectory)) {
            mkdir($this->epubDirectory, 0777, true);
            file_put_contents($this->epubDirectory . '/book.epub', 'Mock EPUB content');
        }
    }

    protected function tearDown(): void
    {
        // Clean up any files or directories created during tests
        if (file_exists($this->outputMobiPath)) {
            unlink($this->outputMobiPath);
        }

        if (is_dir($this->epubDirectory)) {
            FileUtil::deleteDirectory($this->epubDirectory);
        }
    }

    public function testConvertToMobi(): void
    {
        $adapter = new CalibreAdapter(['calibre_path' => $this->calibrePath]);
        $adapter->convert($this->epubDirectory, $this->outputMobiPath);

        $this->assertFileExists($this->outputMobiPath);
        $this->assertGreaterThan(0, filesize($this->outputMobiPath));
    }

    public function testConvertWithInvalidDirectoryThrowsException(): void
    {
        $this->expectException(Exception::class);

        $adapter = new CalibreAdapter(['calibre_path' => $this->calibrePath]);
        $adapter->convert(__DIR__ . '/nonexistent', $this->outputMobiPath);
    }

    public function testConvertWithInvalidCalibrePathThrowsException(): void
    {
        $this->expectException(Exception::class);

        $adapter = new CalibreAdapter(['calibre_path' => '/invalid/path/to/calibre']);
        $adapter->convert($this->epubDirectory, $this->outputMobiPath);
    }
}
