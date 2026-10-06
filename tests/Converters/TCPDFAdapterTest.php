<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use PhpEpub\ConversionException;
use PhpEpub\Converters\TCPDFAdapter;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Test\Support\ExposedTCPDFAdapter;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

final class TCPDFAdapterTest extends TestCase
{
    private string $epubDirectory;
    private string $outputPdfPath;
    private FileSystemHelper $fileSystemHelper;

    protected function setUp(): void
    {
        $this->fileSystemHelper = new FileSystemHelper();
        $this->epubDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'epub_content';
        $this->outputPdfPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'output.pdf';

        // Ensure the directories exist
        if (! is_dir(dirname($this->outputPdfPath))) {
            mkdir(dirname($this->outputPdfPath), 0777, true);
        }

        // Create a mock EPUB content file
        if (! is_dir($this->epubDirectory)) {
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
            $this->fileSystemHelper->deleteDirectory($this->epubDirectory);
        }
    }

    public function testConvertToPdf(): void
    {
        $adapter = new TCPDFAdapter();
        $adapter->convert($this->epubDirectory, $this->outputPdfPath);

        $this->assertFileExists($this->outputPdfPath);
        $this->assertGreaterThan(0, filesize($this->outputPdfPath));
    }

    public function testConvertsEveryChapterOfARealBookWithItsMetadata(): void
    {
        $directory = EpubDocumentLoaderTest::twoChapterBook()->writeTo($this->epubDirectory . '-book');

        try {
            $pdf = $this->exposedAdapter()->createPdfFor($directory);

            // One page per spine document.
            $this->assertSame(2, $pdf->getNumPages());

            (new TCPDFAdapter())->convert($directory, $this->outputPdfPath);
            $output = (string) file_get_contents($this->outputPdfPath);
            $this->assertStringContainsString('Two Chapters', $output);
            $this->assertStringContainsString('Ann Author, Bob Writer', $output);
            $this->assertStringNotContainsString('Author Name', $output);
        } finally {
            $this->fileSystemHelper->deleteDirectory($directory);
        }
    }

    public function testBookWithoutChaptersStillProducesAPage(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', str_replace('<itemref idref="chapter"/>', '', EpubBuilder::opf()))
            ->writeTo($this->epubDirectory . '-empty');

        try {
            $this->assertSame(1, $this->exposedAdapter()->createPdfFor($directory)->getNumPages());
        } finally {
            $this->fileSystemHelper->deleteDirectory($directory);
        }
    }

    public function testInvalidStyleValuesFallBackToTheDeclaredDefaults(): void
    {
        $pdf = $this->exposedAdapter(['font_size' => '14', 'margin_bottom' => 'wide', 'margin_left' => 20])
            ->createPdfFor($this->epubDirectory);

        $this->assertEqualsWithDelta(12.0, $pdf->getFontSizePt(), 0.001);
        $this->assertEqualsWithDelta(25.0, $pdf->getBreakMargin(), 0.001);
        $margins = $pdf->getMargins();
        $this->assertIsArray($margins);
        $this->assertEqualsWithDelta(20.0, $margins['left'], 0.001);
    }

    public function testEveryChapterCarriesTheBookCss(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/chapter.xhtml', '<html><head><style>p { color: #336699; }</style></head><body><p>Text</p></body></html>')
            ->writeTo($this->epubDirectory . '-css');

        try {
            $chapters = $this->exposedAdapter()->chapterHtmlFor($this->epubDirectory . '-css');
        } finally {
            $this->fileSystemHelper->deleteDirectory($this->epubDirectory . '-css');
        }

        $this->assertSame(['<style>p { color: #336699; }</style><p>Text</p>'], $chapters);
    }

    public function testPaperSizeAndOrientationLikeDompdf(): void
    {
        $default = $this->exposedAdapter()->createPdfFor($this->epubDirectory);
        $this->assertEqualsWithDelta(210.0, $default->getPageWidth(), 0.1);
        $this->assertEqualsWithDelta(297.0, $default->getPageHeight(), 0.1);

        $letter = $this->exposedAdapter(['paper_size' => 'letter', 'orientation' => 'landscape'])->createPdfFor($this->epubDirectory);
        $this->assertEqualsWithDelta(279.4, $letter->getPageWidth(), 0.1);
        $this->assertEqualsWithDelta(215.9, $letter->getPageHeight(), 0.1);
    }

    public function testConvertReportsUnwritableOutput(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Failed to write PDF');

        // The output path is an existing directory.
        (new TCPDFAdapter())->convert($this->epubDirectory, $this->epubDirectory);
    }

    public function testConvertWithInvalidDirectoryThrowsException(): void
    {
        $this->expectException(Exception::class);

        $adapter = new TCPDFAdapter();
        $adapter->convert(__DIR__ . '/nonexistent', $this->outputPdfPath);
    }

    /**
     * @param array<string, mixed> $styles
     */
    private function exposedAdapter(array $styles = []): ExposedTCPDFAdapter
    {
        return new ExposedTCPDFAdapter($styles);
    }
}
