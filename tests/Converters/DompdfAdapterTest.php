<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use PhpEpub\ConversionException;
use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Test\Support\ExposedDompdfAdapter;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

final class DompdfAdapterTest extends TestCase
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
        $adapter = new DompdfAdapter();
        $adapter->convert($this->epubDirectory, $this->outputPdfPath);

        $this->assertFileExists($this->outputPdfPath);
        $this->assertGreaterThan(0, filesize($this->outputPdfPath));
    }

    public function testConvertsEveryChapterOfARealBookWithItsMetadata(): void
    {
        $directory = EpubDocumentLoaderTest::twoChapterBook()->writeTo($this->epubDirectory . '-book');

        try {
            $adapter = new DompdfAdapter(['font_size' => 15]);
            $html = $adapter->buildHtml($directory);

            $this->assertStringContainsString('Second file, first in the spine', $html);
            $this->assertStringContainsString('First file, second in the spine', $html);
            $this->assertLessThan(
                strpos($html, 'First file, second in the spine'),
                strpos($html, 'Second file, first in the spine')
            );
            $this->assertSame(1, substr_count($html, 'page-break-before: always'));
            $this->assertStringContainsString('font-size: 15pt', $html);
            $this->assertStringContainsString('<title>Two Chapters</title>', $html);

            $adapter->convert($directory, $this->outputPdfPath);
            $output = (string) file_get_contents($this->outputPdfPath);
            // Dompdf writes document info as UTF-16BE.
            $this->assertStringContainsString(mb_convert_encoding('Two Chapters', 'UTF-16BE', 'UTF-8'), $output);
            $this->assertStringContainsString(mb_convert_encoding('Ann Author, Bob Writer', 'UTF-16BE', 'UTF-8'), $output);
            $this->assertStringContainsString('/Count 2', $output);
        } finally {
            $this->fileSystemHelper->deleteDirectory($directory);
        }
    }

    public function testSvgImagesAreRenderedWithTheImagesTheyReference(): void
    {
        $directory = TCPDFAdapterTest::svgBook()->writeTo($this->epubDirectory . '-svg');

        try {
            (new DompdfAdapter())->convert($directory, $this->outputPdfPath);

            // The PNG drawn by the SVG; Dompdf only reaches it by rendering the SVG.
            $this->assertMatchesRegularExpression('#/Subtype\s*/Image#', (string) file_get_contents($this->outputPdfPath));
        } finally {
            $this->fileSystemHelper->deleteDirectory($directory);
        }
    }

    public function testLinksBetweenChaptersWorkInThePdf(): void
    {
        $directory = TCPDFAdapterTest::linkedBook()->writeTo($this->epubDirectory . '-links');

        try {
            (new DompdfAdapter())->convert($directory, $this->outputPdfPath);

            $this->assertMatchesRegularExpression('#/Dest|/GoTo#', (string) file_get_contents($this->outputPdfPath));
        } finally {
            $this->fileSystemHelper->deleteDirectory($directory);
        }
    }

    public function testACoverOutsideTheSpineBecomesTheFirstPage(): void
    {
        $directory = TCPDFAdapterTest::coverBook()->writeTo($this->epubDirectory . '-cover');

        try {
            $dompdf = (new ExposedDompdfAdapter())->createDompdfFor($directory);
            $dompdf->loadHtml((new DompdfAdapter())->buildHtml($directory));
            $dompdf->render();

            $this->assertSame(2, $dompdf->getCanvas()->get_page_count());
            $this->assertMatchesRegularExpression('#/Subtype\s*/Image#', (string) $dompdf->output(['compress' => 0]));
        } finally {
            $this->fileSystemHelper->deleteDirectory($directory);
        }
    }

    public function testRendererCannotReachOutsideTheBook(): void
    {
        $dompdf = (new ExposedDompdfAdapter())->createDompdfFor($this->epubDirectory);
        $options = $dompdf->getOptions();

        $this->assertFalse($options->isRemoteEnabled());
        $this->assertFalse($options->isPhpEnabled());
        $this->assertFalse($options->isJavascriptEnabled());
        $this->assertSame([realpath($this->epubDirectory)], $options->getChroot());
    }

    public function testBookCssFollowsTheAdapterDefaults(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/chapter.xhtml', '<html><head><link rel="stylesheet" href="book.css"/></head><body><p>Text</p></body></html>')
            ->withFile('EPUB/book.css', 'p { text-align: justify; } q::after { content: "</style><script>x</script>"; }')
            ->writeTo($this->epubDirectory . '-css');

        try {
            $html = (new DompdfAdapter())->buildHtml($this->epubDirectory . '-css');
        } finally {
            (new FileSystemHelper())->deleteDirectory($this->epubDirectory . '-css');
        }

        $this->assertMatchesRegularExpression('/font-size: 12pt;.*p \{ text-align: justify; \}/s', $html);
        // Book CSS cannot close the <style> element.
        $this->assertSame(2, substr_count(strtolower($html), '</style>'));
    }

    public function testMarginsLikeTcpdf(): void
    {
        $html = (new DompdfAdapter(['margin_top' => 10, 'margin_right' => 11, 'margin_bottom' => 12, 'margin_left' => 13]))
            ->buildHtml($this->epubDirectory);

        $this->assertStringContainsString('@page { margin: 10mm 11mm 12mm 13mm; }', $html);
    }

    public function testDompdfKeepsItsOwnMarginsWhenNoneAreGiven(): void
    {
        $this->assertStringNotContainsString('@page', (new DompdfAdapter())->buildHtml($this->epubDirectory));
    }

    public function testConvertReportsUnwritableOutput(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Failed to write PDF');

        // The output path is an existing directory.
        (new DompdfAdapter())->convert($this->epubDirectory, $this->epubDirectory);
    }

    public function testConvertWithInvalidDirectoryThrowsException(): void
    {
        $this->expectException(Exception::class);

        $adapter = new DompdfAdapter();
        $adapter->convert(__DIR__ . '/nonexistent', $this->outputPdfPath);
    }
}
