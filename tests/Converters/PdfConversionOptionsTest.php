<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use Closure;
use Iterator;
use PhpEpub\ConversionException;
use PhpEpub\Converters\ConverterInterface;
use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\Converters\EpubDocumentLoader;
use PhpEpub\Converters\PdfConversionOptions;
use PhpEpub\Converters\TCPDFAdapter;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PdfConversionOptionsTest extends TestCase
{
    private const string MEDIA_BOX = '#/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]#';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_options_' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->directory);
    }

    /**
     * @return Iterator<string, array{Closure(PdfConversionOptions): ConverterInterface}>
     */
    public static function adapters(): Iterator
    {
        yield 'dompdf' => [static fn (PdfConversionOptions $options): ConverterInterface => new DompdfAdapter([], new EpubDocumentLoader(), $options)];
        yield 'tcpdf' => [static fn (PdfConversionOptions $options): ConverterInterface => new TCPDFAdapter([], new EpubDocumentLoader(), $options)];
    }

    /**
     * A book with $chapters chapters; extra manifest items, metadata and per-itemref properties can be added.
     *
     * @param array<int, string> $properties itemref properties by chapter index (0-based)
     */
    private function book(int $chapters, string $manifestItems = '', string $metadata = '', array $properties = [], string $body = '<p>Text</p>'): string
    {
        $items = '';
        $refs = '';
        $builder = EpubBuilder::minimal();
        for ($i = 0; $i < $chapters; ++$i) {
            $props = isset($properties[$i]) ? ' properties="' . $properties[$i] . '"' : '';
            $items .= "<item id=\"c{$i}\" href=\"c{$i}.xhtml\" media-type=\"application/xhtml+xml\"/>";
            $refs .= "<itemref idref=\"c{$i}\"{$props}/>";
            $builder->withFile("EPUB/c{$i}.xhtml", EpubBuilder::xhtml("C{$i}", "<h1>Chapter {$i}</h1>" . $body));
        }

        $opf = str_replace(
            ['<item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/>', '<itemref idref="chapter"/>'],
            [$items . $manifestItems, $refs],
            EpubBuilder::opf('', $metadata)
        );

        return $builder->withoutFile('EPUB/chapter.xhtml')->withFile('EPUB/package.opf', $opf)->writeTo($this->directory . '/' . bin2hex(random_bytes(4)));
    }

    /**
     * @param Closure(PdfConversionOptions): ConverterInterface $adapter
     */
    private function convert(Closure $adapter, PdfConversionOptions $options, string $book): string
    {
        $output = $this->directory . '/out.pdf';
        $adapter($options)->convert($book, $output);

        return (string) file_get_contents($output);
    }

    /**
     * The width and height, in points, of the first page's MediaBox.
     *
     * @return array{float, float}
     */
    private function mediaBox(string $pdf): array
    {
        if (preg_match(self::MEDIA_BOX, $pdf, $match) !== 1) {
            $this->fail('No MediaBox found');
        }

        return [(float) $match[1], (float) $match[2]];
    }

    /**
     * @param Closure(PdfConversionOptions): ConverterInterface $adapter
     */
    private function assertRefused(int $code, Closure $adapter, PdfConversionOptions $options, string $book, string $message): void
    {
        try {
            $this->convert($adapter, $options, $book);
            $this->fail('Expected a ConversionException');
        } catch (ConversionException $exception) {
            $this->assertSame($code, $exception->getCode());
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * @param Closure(PdfConversionOptions): ConverterInterface $adapter
     */
    #[DataProvider('adapters')]
    public function testChapterLimitAtTheBoundary(Closure $adapter): void
    {
        $book = $this->book(3);

        $this->assertStringStartsWith('%PDF', $this->convert($adapter, new PdfConversionOptions(maxChapters: 3), $book));
        $this->assertRefused(ConversionException::CODE_BUDGET_EXCEEDED, $adapter, new PdfConversionOptions(maxChapters: 2), $book, 'more than the limit of 2');
    }

    /**
     * @param Closure(PdfConversionOptions): ConverterInterface $adapter
     */
    #[DataProvider('adapters')]
    public function testHtmlLimitAtTheBoundary(Closure $adapter): void
    {
        $book = $this->book(3);
        $bytes = array_sum(array_map(strlen(...), (new EpubDocumentLoader())->load($book)->chapters));

        $this->assertGreaterThan(0, $bytes);
        $this->assertStringStartsWith('%PDF', $this->convert($adapter, new PdfConversionOptions(maxHtmlBytes: $bytes), $book));
        $this->assertRefused(ConversionException::CODE_BUDGET_EXCEEDED, $adapter, new PdfConversionOptions(maxHtmlBytes: $bytes - 1), $book, 'HTML is over the limit');
    }

    /**
     * @param Closure(PdfConversionOptions): ConverterInterface $adapter
     */
    #[DataProvider('adapters')]
    public function testImageLimitAtTheBoundary(Closure $adapter): void
    {
        $png = (string) base64_decode(EpubBuilder::PNG, true);
        $book = $this->book(
            2,
            '<item id="img" href="img.png" media-type="image/png"/>',
            '',
            [],
            '<p><img src="img.png" alt=""/></p>'
        );
        file_put_contents($book . '/EPUB/img.png', $png);
        // The same file used by both chapters counts once.
        $bytes = strlen($png);

        $this->assertStringStartsWith('%PDF', $this->convert($adapter, new PdfConversionOptions(maxImageBytes: $bytes), $book));
        $this->assertRefused(ConversionException::CODE_BUDGET_EXCEEDED, $adapter, new PdfConversionOptions(maxImageBytes: $bytes - 1), $book, 'images are over the limit');
    }

    public function testDataUrisCountEachTimeTheyAreUsed(): void
    {
        $size = strlen((string) base64_decode(EpubBuilder::PNG, true));
        $book = $this->book(2, '', '', [], '<p><img src="data:image/png;base64,' . EpubBuilder::PNG . '" alt=""/></p>');
        $loader = new EpubDocumentLoader();

        $this->assertCount(2, $loader->load($book, new PdfConversionOptions(maxImageBytes: 2 * $size))->chapters);
        $this->expectException(ConversionException::class);
        $loader->load($book, new PdfConversionOptions(maxImageBytes: 2 * $size - 1));
    }

    /**
     * @param Closure(PdfConversionOptions): ConverterInterface $adapter
     */
    #[DataProvider('adapters')]
    public function testTimeBudget(Closure $adapter): void
    {
        $book = $this->book(2);

        $this->assertStringStartsWith('%PDF', $this->convert($adapter, new PdfConversionOptions(timeBudgetSeconds: 600.0), $book));
        $this->assertRefused(ConversionException::CODE_TIME_BUDGET_EXCEEDED, $adapter, new PdfConversionOptions(timeBudgetSeconds: 0.0), $book, 'time budget of 0 seconds');
    }

    /**
     * @param Closure(PdfConversionOptions): ConverterInterface $adapter
     */
    #[DataProvider('adapters')]
    public function testFixedLayoutIsRefusedUnlessAllowed(Closure $adapter): void
    {
        $book = $this->book(2, '', '<meta property="rendition:layout">pre-paginated</meta>');

        $this->assertRefused(ConversionException::CODE_FIXED_LAYOUT, $adapter, new PdfConversionOptions(), $book, 'fixed layout');
        $this->assertStringStartsWith('%PDF', $this->convert($adapter, new PdfConversionOptions(allowFixedLayout: true), $book));
    }

    public function testFixedLayoutFollowsTheMajorityOfTheSpine(): void
    {
        $fixed = 'rendition:layout-pre-paginated';
        $loader = new EpubDocumentLoader();
        $options = new PdfConversionOptions();

        // One of three pages fixed: reflowable overall. Two of three: refused.
        $this->assertCount(3, $loader->load($this->book(3, '', '', [0 => $fixed]), $options)->chapters);
        try {
            $loader->load($this->book(3, '', '', [0 => $fixed, 1 => $fixed]), $options);
            $this->fail('Expected a ConversionException');
        } catch (ConversionException $exception) {
            $this->assertSame(ConversionException::CODE_FIXED_LAYOUT, $exception->getCode());
        }

        // A fixed-layout book whose pages mostly override it back to reflowable is accepted.
        $overridden = $this->book(
            3,
            '',
            '<meta property="rendition:layout">pre-paginated</meta>',
            [0 => 'rendition:layout-reflowable', 1 => 'rendition:layout-reflowable']
        );
        $this->assertCount(3, $loader->load($overridden, $options)->chapters);
    }

    /**
     * @param Closure(PdfConversionOptions): ConverterInterface $adapter
     */
    #[DataProvider('adapters')]
    public function testDefaultsConvertAReflowableBook(Closure $adapter): void
    {
        $this->assertStringStartsWith('%PDF', $this->convert($adapter, new PdfConversionOptions(), $this->book(2)));
    }

    /**
     * @param Closure(PdfConversionOptions): ConverterInterface $adapter
     */
    #[DataProvider('adapters')]
    public function testCustomPageSizeIsInTheMediaBox(Closure $adapter): void
    {
        $pdf = $this->convert($adapter, new PdfConversionOptions(pageWidthMm: 100.0, pageHeightMm: 150.0), $this->book(1));

        [$width, $height] = $this->mediaBox($pdf);
        $this->assertEqualsWithDelta(100 * 72 / 25.4, $width, 0.1);
        $this->assertEqualsWithDelta(150 * 72 / 25.4, $height, 0.1);
    }

    /**
     * @param Closure(PdfConversionOptions): ConverterInterface $adapter
     */
    #[DataProvider('adapters')]
    public function testWithoutACustomPageSizeThePaperSizeStyleApplies(Closure $adapter): void
    {
        $pdf = $this->convert($adapter, new PdfConversionOptions(), $this->book(1));

        [$width, $height] = $this->mediaBox($pdf);
        $this->assertEqualsWithDelta(595.28, $width, 0.5);
        $this->assertEqualsWithDelta(841.89, $height, 0.5);
    }

    public function testCoverCanBeLeftOut(): void
    {
        $book = $this->book(1, '<item id="cover" href="cover.png" media-type="image/png" properties="cover-image"/>');
        file_put_contents($book . '/EPUB/cover.png', base64_decode(EpubBuilder::PNG, true));
        $loader = new EpubDocumentLoader();

        $this->assertNotSame('', $loader->load($book, new PdfConversionOptions())->coverImage);
        $this->assertSame('', $loader->load($book, new PdfConversionOptions(includeCover: false))->coverImage);
    }

    /**
     * An EPUB 3 book whose navigation document holds a nested table of contents with markup in a title.
     */
    private function bookWithToc(): string
    {
        $nav = '<nav epub:type="toc"><h1>Contents</h1><ol>'
            . '<li><a href="text/chapter.xhtml">Fish &amp; &lt;b&gt;Chips&lt;/b&gt;</a>'
            . '<ol><li><a href="text/chapter.xhtml#sub">Nested</a></li></ol></li>'
            . '<li><span>No link</span></li></ol></nav>';

        return EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml('Contents', $nav))->writeTo($this->directory . '/toc');
    }

    public function testContentsPageListsTheTitlesEscapedAndNested(): void
    {
        $adapter = new DompdfAdapter([], new EpubDocumentLoader(), new PdfConversionOptions(includeToc: true));
        $html = $adapter->buildHtml($this->bookWithToc());

        $this->assertStringContainsString('<h1>Contents</h1><ul><li><a href="#epub-c0">Fish &amp; &lt;b&gt;Chips&lt;/b&gt;</a><ul><li><a href="#epub-c0-sub">Nested</a></li></ul></li><li>No link</li></ul>', $html);
        // The contents page comes before the chapter.
        $this->assertLessThan((int) strpos($html, 'Text.'), (int) strpos($html, 'Nested'));
    }

    public function testContentsPageIsOptIn(): void
    {
        $this->assertStringNotContainsString('<h1>Contents</h1><ul>', (new DompdfAdapter())->buildHtml($this->bookWithToc()));
    }

    public function testTcpdfAddsAContentsPage(): void
    {
        $with = $this->directory . '/with.pdf';
        $without = $this->directory . '/without.pdf';
        (new TCPDFAdapter([], new EpubDocumentLoader(), new PdfConversionOptions(includeToc: true)))->convert($this->bookWithToc(), $with);
        (new TCPDFAdapter())->convert($this->bookWithToc(), $without);

        $pages = static fn (string $file): int => (int) preg_match_all('#/Type\s*/Page\b#', (string) file_get_contents($file));
        $this->assertSame($pages($without) + 1, $pages($with));
    }

    public function testABookWithoutATableOfContentsGetsNoContentsPage(): void
    {
        $document = (new EpubDocumentLoader())->load($this->book(1), new PdfConversionOptions(includeToc: true));

        $this->assertSame('', $document->contents);
    }

    /**
     * @return Iterator<string, array{array<string, mixed>, string}>
     */
    public static function invalidOptions(): Iterator
    {
        yield 'negative chapters' => [['maxChapters' => -1], 'maxChapters must not be negative'];
        yield 'negative html' => [['maxHtmlBytes' => -1], 'maxHtmlBytes must not be negative'];
        yield 'negative images' => [['maxImageBytes' => -1], 'maxImageBytes must not be negative'];
        yield 'negative time' => [['timeBudgetSeconds' => -0.5], 'timeBudgetSeconds'];
        yield 'width alone' => [['pageWidthMm' => 100.0], 'must be given together'];
        yield 'zero height' => [['pageWidthMm' => 100.0, 'pageHeightMm' => 0.0], 'pageHeightMm must be'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejected(array $arguments, string $message): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);

        (new ReflectionClass(PdfConversionOptions::class))->newInstanceArgs($arguments);
    }
}
