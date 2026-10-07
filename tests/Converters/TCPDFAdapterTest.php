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

    public function testSvgImagesAreRenderedWithTheImagesTheyReference(): void
    {
        $directory = self::svgBook()->writeTo($this->epubDirectory . '-svg');
        $svgFiles = glob(sys_get_temp_dir() . '/epu*.svg') ?: [];

        try {
            (new TCPDFAdapter())->convert($directory, $this->outputPdfPath);

            // The PNG drawn by the SVG; TCPDF only reaches it by rendering the SVG.
            $this->assertMatchesRegularExpression('#/Subtype\s*/Image#', (string) file_get_contents($this->outputPdfPath));
            $this->assertSame($svgFiles, glob(sys_get_temp_dir() . '/epu*.svg') ?: [], 'Temporary SVG files are deleted.');
        } finally {
            $this->fileSystemHelper->deleteDirectory($directory);
        }
    }

    public static function svgBook(): EpubBuilder
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="100" height="100">'
            . '<image xlink:href="pixel.png" width="40" height="40"/></svg>';

        return EpubBuilder::minimal()
            ->withFile('EPUB/chapter.xhtml', '<html><body><p>Drawing</p><img src="images/drawing.svg" width="100" height="100"/></body></html>')
            ->withFile('EPUB/images/drawing.svg', $svg)
            ->withFile('EPUB/images/pixel.png', (string) base64_decode(EpubBuilder::PNG, true));
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

    public function testEveryChapterGetsABookmark(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', str_replace(
                '<itemref idref="chapter"/>',
                '<itemref idref="chapter"/><itemref idref="titled"/><itemref idref="bare"/>',
                EpubBuilder::opf(
                    '<item id="titled" href="titled.xhtml" media-type="application/xhtml+xml"/>'
                    . '<item id="bare" href="bare.xhtml" media-type="application/xhtml+xml"/>'
                )
            ))
            ->withFile('EPUB/chapter.xhtml', '<html><head><title>Book</title></head><body><h2>  The   First  Chapter </h2><h1>Later</h1></body></html>')
            ->withFile('EPUB/titled.xhtml', '<html><head><title>Second Title</title></head><body><p>No heading</p></body></html>')
            ->withFile('EPUB/bare.xhtml', '<html><body><p>Nothing</p></body></html>')
            ->writeTo($this->epubDirectory . '-toc');
        $adapter = $this->exposedAdapter();

        try {
            $adapter->createPdfFor($this->epubDirectory . '-toc');
            $this->assertSame([['The First Chapter', 0], ['Second Title', 0], ['Chapter 3', 0]], $adapter->bookmarks);

            $withoutBookmarks = $this->exposedAdapter(['bookmarks' => false]);
            $withoutBookmarks->createPdfFor($this->epubDirectory . '-toc');
            $this->assertSame([], $withoutBookmarks->bookmarks);
        } finally {
            $this->fileSystemHelper->deleteDirectory($this->epubDirectory . '-toc');
        }
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

    public function testHeaderAndFooterCanBeSwitchedOff(): void
    {
        $withThem = $this->exposedAdapter()->createPdfFor($this->epubDirectory);
        $withoutThem = $this->exposedAdapter(['header' => false, 'footer' => false])->createPdfFor($this->epubDirectory);

        // Without a header and footer, the same content needs fewer bytes of page drawing.
        $this->assertLessThan(strlen($withThem->Output('', 'S')), strlen($withoutThem->Output('', 'S')));
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
