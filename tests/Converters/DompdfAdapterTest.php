<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use Dompdf\Dompdf;
use Iterator;
use PhpEpub\ConversionException;
use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Test\Support\ExposedDompdfAdapter;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testFractionalMillimetresAreAccepted(): void
    {
        $html = (new DompdfAdapter(['margin_top' => 12.5, 'margin_left' => 10, 'font_size' => 10.5]))->buildHtml($this->epubDirectory);

        $this->assertStringContainsString('@page { margin: 12.5mm 0mm 0mm 10mm; }', $html);
        $this->assertStringContainsString('font-size: 10.5pt', $html);
    }

    /**
     * @param array<string, mixed> $styles
     */
    #[DataProvider('invalidStyleProvider')]
    public function testInvalidStylesAreRejected(array $styles, string $message): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);

        new DompdfAdapter($styles);
    }

    public static function invalidStyleProvider(): Iterator
    {
        yield 'unknown key' => [['fontsize' => 12], 'does not know the style "fontsize"; the styles are: font, font_size, paper_size, orientation, margin_top,'];
        yield 'wrong type for a number' => [['margin_top' => '10'], 'style "margin_top" must be a number (int or float), string given'];
        yield 'wrong type for a string' => [['orientation' => true], 'style "orientation" must be a string, bool given'];
        yield 'unknown paper size' => [['paper_size' => 'A44'], 'style "paper_size" is not a paper size the renderer knows, \'A44\' given'];
        yield 'unknown orientation' => [['orientation' => 'diagonal'], 'style "orientation" must be "portrait" or "landscape"'];
        yield 'negative margin' => [['margin_right' => -0.5], 'style "margin_right" must not be negative'];
        yield 'zero font size' => [['font_size' => 0.0], 'style "font_size" must be greater than zero'];
    }

    public function testPaperSizesAreCheckedWhateverTheirCase(): void
    {
        $html = (new DompdfAdapter(['paper_size' => 'Letter', 'orientation' => 'LANDSCAPE']))->buildHtml($this->epubDirectory);

        $this->assertStringContainsString('<html', $html);
    }

    public function testTheDefaultFontCoversNonLatinScripts(): void
    {
        $directory = EpubDocumentLoaderTest::languageBook('ru', null, 'Привет, мир. Γειά σου κόσμε.')->writeTo($this->epubDirectory . '-ru');

        try {
            $adapter = new DompdfAdapter();
            $this->assertStringContainsString('font-family: "DejaVu Sans"', $adapter->buildHtml($directory));
            $this->assertStringNotContainsString('dir="rtl"', $adapter->buildHtml($directory));

            $adapter->convert($directory, $this->outputPdfPath);
            $this->assertStringContainsString('DejaVuSans', (string) file_get_contents($this->outputPdfPath));
        } finally {
            $this->fileSystemHelper->deleteDirectory($directory);
        }
    }

    public function testAGivenFontWins(): void
    {
        $html = (new DompdfAdapter(['font' => 'Times']))->buildHtml($this->epubDirectory);

        $this->assertStringContainsString('font-family: "Times"', $html);
        $this->assertStringNotContainsString('DejaVu', $html);
    }

    #[DataProvider('readingDirectionProvider')]
    public function testRightToLeftBooksAreRenderedRightToLeft(string $language, ?string $direction, bool $expected): void
    {
        $directory = EpubDocumentLoaderTest::languageBook($language, $direction, 'مرحبا שלום')->writeTo($this->epubDirectory . '-rtl');

        try {
            $adapter = new DompdfAdapter();
            $html = $adapter->buildHtml($directory);
            $this->assertSame($expected, str_contains($html, '<html dir="rtl"'));
            $this->assertSame($expected, str_contains($html, '<body dir="rtl">'));
            $this->assertStringContainsString("lang=\"{$language}\"", $html);

            $adapter->convert($directory, $this->outputPdfPath);
            $this->assertGreaterThan(0, filesize($this->outputPdfPath));
        } finally {
            $this->fileSystemHelper->deleteDirectory($directory);
        }
    }

    public static function readingDirectionProvider(): Iterator
    {
        yield 'Arabic' => ['ar', null, true];
        yield 'Hebrew' => ['he', null, true];
        yield 'English with an rtl spine' => ['en', 'rtl', true];
        yield 'English' => ['en', null, false];
        yield 'Arabic with an ltr spine' => ['ar', 'ltr', false];
    }

    public function testRendererFailuresBecomeConversionExceptions(): void
    {
        $adapter = new class extends DompdfAdapter {
            protected function createDompdf(string $epubDirectory): Dompdf
            {
                return new class extends Dompdf {
                    public function render(): void
                    {
                        throw new \RuntimeException('Dompdf broke');
                    }
                };
            }
        };

        try {
            $adapter->convert($this->epubDirectory, $this->outputPdfPath);
            $this->fail('A ConversionException was expected.');
        } catch (ConversionException $exception) {
            $this->assertSame('Dompdf failed to render the book: Dompdf broke', $exception->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }

        $this->assertFileDoesNotExist($this->outputPdfPath);
    }

    public function testTheLibrarysOwnExceptionsAreNotWrapped(): void
    {
        $failure = new Exception('library failure');
        $adapter = new class ($failure) extends DompdfAdapter {
            public function __construct(private readonly Exception $failure)
            {
                parent::__construct();
            }

            protected function createDompdf(string $epubDirectory): Dompdf
            {
                throw $this->failure;
            }
        };

        try {
            $adapter->convert($this->epubDirectory, $this->outputPdfPath);
            $this->fail('An Exception was expected.');
        } catch (Exception $exception) {
            $this->assertSame($failure, $exception);
        }
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
