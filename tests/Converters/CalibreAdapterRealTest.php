<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use PhpEpub\Converters\CalibreAdapter;
use PhpEpub\Exception;
use PHPUnit\Framework\TestCase;

final class CalibreAdapterRealTest extends TestCase
{
    private string $validFile;
    private string $invalidFile;
    private string $outputMobiPath;
    private string $calibrePath;

    protected function setUp(): void
    {
        $this->validFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub';
        $this->invalidFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'invalid.epub';
        $this->outputMobiPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'output.mobi';
        $this->calibrePath = '/usr/bin/ebook-convert'; // Adjust this path to your Calibre installation

        if (! file_exists($this->calibrePath)) {
            $this->markTestSkipped('Calibre is not installed or the path is incorrect.');
        }

        // Ensure the directories exist
        if (! is_dir(dirname($this->outputMobiPath))) {
            mkdir(dirname($this->outputMobiPath), 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any files or directories created during tests
        if (file_exists($this->outputMobiPath)) {
            unlink($this->outputMobiPath);
        }
    }

    public function testConvertToMobi(): void
    {
        $adapter = new CalibreAdapter(['calibre_path' => $this->calibrePath]);
        $adapter->convert($this->validFile, $this->outputMobiPath);

        $this->assertFileExists($this->outputMobiPath);
        $this->assertGreaterThan(0, filesize($this->outputMobiPath));
    }

    public function testConvertWithInvalidFileThrowsException(): void
    {
        $this->expectException(Exception::class);

        $adapter = new CalibreAdapter(['calibre_path' => $this->calibrePath]);
        $adapter->convert($this->invalidFile, $this->outputMobiPath);
    }

    public function testConvertWithNonExistentFileThrowsException(): void
    {
        $this->expectException(Exception::class);

        $adapter = new CalibreAdapter(['calibre_path' => $this->calibrePath]);
        $adapter->convert(__DIR__ . '/nonexistent', $this->outputMobiPath);
    }
}
