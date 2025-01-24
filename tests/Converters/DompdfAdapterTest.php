<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\Exception;
use PhpEpub\Util\FileUtil;
use PHPUnit\Framework\TestCase;
use Dompdf\Dompdf;

class DompdfAdapterTest extends TestCase
{
    private string $epubDirectory;
    private string $outputPdfPath;

    protected function setUp(): void
    {
        $this->epubDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'epub_content';
        $this->outputPdfPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'output.pdf';

        // Ensure the directories exist
        if (!is_dir(dirname($this->outputPdfPath))) {
            mkdir(dirname($this->outputPdfPath), 0777, true);
        }

        // Create a mock EPUB content file
        if (!is_dir($this->epubDirectory)) {
            mkdir($this->epubDirectory, 0777, true);
            file_put_contents($this->epubDirectory . '/content.xhtml', '<html><body>Sample Content</body></html>');
        }
    }

    protected function tearDown(): void
    {
        // Clean up any files or directories created during tests
        if (file_exists($this->outputPdfPath)) {
            unlink($this->outputPdfPath);
        }

        if (is_dir($this->epubDirectory)) {
            FileUtil::deleteDirectory($this->epubDirectory);
        }
    }

    public function testConvertToPdf(): void
    {
        $adapter = new DompdfAdapter();
        $adapter->convert($this->epubDirectory, $this->outputPdfPath);

        $this->assertFileExists($this->outputPdfPath);
        $this->assertGreaterThan(0, filesize($this->outputPdfPath));
    }

    public function testConvertWithInvalidDirectoryThrowsException(): void
    {
        $this->expectException(Exception::class);

        $adapter = new DompdfAdapter();
        $adapter->convert(__DIR__ . '/nonexistent', $this->outputPdfPath);
    }
}
