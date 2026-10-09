<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Cleanup\Cleanup;
use PhpEpub\Cleanup\CleanupOptions;
use PhpEpub\Cleanup\ReferenceGraph;
use PhpEpub\ConversionException;
use PhpEpub\Converters\EpubDocumentLoader;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\InvalidEpubException;
use PhpEpub\Limits;
use PhpEpub\Merge\MergeOptions;
use PhpEpub\Merge\Merger;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Test\Support\MergeBook;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;
use PhpEpub\ZipException;
use PhpEpub\ZipHandler;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class LimitsTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_limits_' . bin2hex(random_bytes(8));
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->workDir);
    }

    public function testDefaultAndWebPresets(): void
    {
        $default = Limits::default();
        $this->assertSame(
            [10_000, 1024 ** 3, 100, PHP_INT_MAX, PHP_INT_MAX],
            [$default->maxEntries, $default->maxUncompressedBytes, $default->maxCompressionRatio, $default->maxXmlBytes, $default->maxHtmlBytes]
        );

        $web = Limits::web();
        $this->assertSame(
            [2_000, 200 * 1024 * 1024, 100, 8 * 1024 * 1024, 8 * 1024 * 1024],
            [$web->maxEntries, $web->maxUncompressedBytes, $web->maxCompressionRatio, $web->maxXmlBytes, $web->maxHtmlBytes]
        );
    }

    public function testALimitMustBePositive(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('maxXmlBytes');

        new Limits(maxXmlBytes: 0);
    }

    public function testWebLimitsOpenTheFixtures(): void
    {
        foreach (glob(__DIR__ . '/fixtures/valid*.epub') ?: [] as $fixture) {
            $epubFile = EpubFile::open($fixture, limits: Limits::web());
            $this->assertNotSame('', $epubFile->getMetadata()->getTitle());
            $epubFile->close();
        }
    }

    public function testEntryCountBoundary(): void
    {
        $path = $this->book(EpubBuilder::epub3());
        $entries = $this->zipValue($path, static fn (ZipArchive $zip): int => $zip->numFiles);

        EpubFile::open($path, limits: new Limits(maxEntries: $entries))->close();

        $this->expectException(ZipException::class);
        EpubFile::open($path, limits: new Limits(maxEntries: $entries - 1));
    }

    public function testUncompressedSizeBoundary(): void
    {
        $path = $this->book(EpubBuilder::epub3());
        $total = $this->zipValue($path, static function (ZipArchive $zip): int {
            $sum = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $sum += (int) ($zip->statIndex($index)['size'] ?? 0);
            }

            return $sum;
        });

        EpubFile::open($path, limits: new Limits(maxUncompressedBytes: $total))->close();

        $this->expectException(ZipException::class);
        EpubFile::open($path, limits: new Limits(maxUncompressedBytes: $total - 1));
    }

    public function testCompressionRatioBoundary(): void
    {
        $path = $this->book(EpubBuilder::epub3()->withFile('EPUB/big.bin', str_repeat("\0", 2 * 1024 * 1024)));
        $compressed = $this->zipValue($path, static fn (ZipArchive $zip): int => (int) ($zip->statName('EPUB/big.bin')['comp_size'] ?? 1));
        $ratio = (int) ceil(2 * 1024 * 1024 / $compressed);

        EpubFile::open($path, limits: new Limits(maxCompressionRatio: $ratio))->close();

        $this->expectException(ZipException::class);
        EpubFile::open($path, limits: new Limits(maxCompressionRatio: $ratio - 1));
    }

    public function testXmlSizeBoundary(): void
    {
        $builder = EpubBuilder::epub3();
        $opf = str_replace('</package>', '<!--' . str_repeat('x', 3000) . '--></package>', (string) $builder->getFile('EPUB/package.opf'));
        $path = $this->book($builder->withFile('EPUB/package.opf', $opf));

        EpubFile::open($path, limits: new Limits(maxXmlBytes: strlen($opf)))->close();

        $this->expectException(XmlException::class);
        EpubFile::open($path, limits: new Limits(maxXmlBytes: strlen($opf) - 1));
    }

    public function testExplicitCollaboratorsWinOverLimits(): void
    {
        $path = $this->book(EpubBuilder::epub3());

        $epubFile = EpubFile::open($path, new ZipHandler(), limits: new Limits(maxEntries: 1));
        $this->assertSame('Valid Book', $epubFile->getMetadata()->getTitle());
        $epubFile->close();
    }

    public function testXmlParserCapsFilesAndStrings(): void
    {
        $xml = '<a>' . str_repeat('b', 100) . '</a>';
        $file = $this->workDir . '/doc.xml';
        file_put_contents($file, $xml);

        $parser = new XmlParser(strlen($xml));
        $this->assertSame(100, strlen((string) $parser->parse($file)));
        $this->assertSame(100, strlen((string) $parser->parseString($xml)));

        $strict = new XmlParser(strlen($xml) - 1);
        foreach ([static fn () => $strict->parse($file), static fn () => $strict->parseString($xml)] as $parse) {
            try {
                $parse();
                $this->fail('Expected an XmlException.');
            } catch (XmlException $exception) {
                $this->assertStringContainsString('larger than the limit', $exception->getMessage());
            }
        }
    }

    public function testDeeplyNestedXmlIsRefused(): void
    {
        $xml = str_repeat('<a>', 5000) . str_repeat('</a>', 5000);

        $this->expectException(XmlException::class);
        (new XmlParser())->parseString($xml);
    }

    public function testHtmlSizeBoundaryForText(): void
    {
        $chapter = EpubBuilder::xhtml('Chapter', '<p>' . str_repeat('word ', 200) . '</p>');
        $path = $this->book(EpubBuilder::epub3()->withFile('EPUB/text/chapter.xhtml', $chapter));

        $epubFile = EpubFile::open($path, limits: new Limits(maxHtmlBytes: strlen($chapter)));
        $this->assertStringContainsString('word', $epubFile->getText()['EPUB/text/chapter.xhtml']);
        $epubFile->close();

        $epubFile = EpubFile::open($path, limits: new Limits(maxHtmlBytes: strlen($chapter) - 1));
        try {
            $this->expectException(InvalidEpubException::class);
            $epubFile->getText();
        } finally {
            $epubFile->close();
        }
    }

    public function testValidateAppliesTheXmlCapToContentDocuments(): void
    {
        $chapter = EpubBuilder::xhtml('Chapter', '<p>x</p><!--' . str_repeat('x', 3000) . '-->');
        $path = $this->book(EpubBuilder::epub3()->withFile('EPUB/text/chapter.xhtml', $chapter));

        $epubFile = EpubFile::open($path);
        $this->assertSame([], $epubFile->validate());
        $epubFile->close();

        $epubFile = EpubFile::open($path, limits: new Limits(maxXmlBytes: strlen($chapter) - 1));
        $messages = array_map(static fn (\PhpEpub\ValidationIssue $issue): string => $issue->message, $epubFile->validate());
        $epubFile->close();

        $this->assertNotEmpty(array_filter($messages, static fn (string $message): bool => str_contains($message, 'larger than the limit')));
    }

    public function testContentManagerEditsApplyTheXmlCap(): void
    {
        $path = $this->book(EpubBuilder::epub3());
        $epubFile = EpubFile::open($path, limits: new Limits(maxXmlBytes: 2000));
        $chapter = EpubBuilder::xhtml('Chapter', '<p>x</p><!--' . str_repeat('x', 3000) . '-->');

        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('larger than the limit');
            $epubFile->getContentManager()->updateContent('EPUB/text/chapter.xhtml', $chapter);
        } finally {
            $epubFile->close();
        }
    }

    public function testCleanupAndReferenceGraphApplyTheBooksXmlCap(): void
    {
        $chapter = EpubBuilder::xhtml('Chapter', '<p>x</p><script>1</script><!--' . str_repeat('x', 3000) . '-->');
        $path = $this->book(EpubBuilder::epub3()->withFile('EPUB/text/chapter.xhtml', $chapter));

        $epubFile = EpubFile::open($path, limits: new Limits(maxXmlBytes: strlen($chapter) - 1));

        try {
            $this->assertSame($epubFile->getXmlParser(), (new \ReflectionProperty(ReferenceGraph::class, 'xmlParser'))->getValue(ReferenceGraph::forBook($epubFile)));
            $this->assertContains('EPUB/text/chapter.xhtml', ReferenceGraph::forBook($epubFile)->analyze()->unparsable);

            $report = (new Cleanup($epubFile))->run(new CleanupOptions(stripScripts: true));
            $this->assertStringContainsString('<script>', (string) file_get_contents($epubFile->getTempDir() . '/EPUB/text/chapter.xhtml'));
            $this->assertStringContainsString('could not be parsed', $report->actions[0]->note);
        } finally {
            $epubFile->close();
        }
    }

    public function testMergerParsesEachBookWithItsCappedXmlParser(): void
    {
        $body = [1 => '<p>x</p><!--' . str_repeat('x', 20000) . '-->'];
        $books = [
            MergeBook::builder('urn:uuid:11111111-1111-4111-8111-111111111111', 'One', 1, chapterBodies: $body),
            MergeBook::builder('urn:uuid:22222222-2222-4222-8222-222222222222', 'Two', 1, chapterBodies: $body),
        ];

        $deduplicated = [];
        foreach ([null, new Limits(maxXmlBytes: 10000)] as $limits) {
            $opened = array_map(fn (EpubBuilder $builder): EpubFile => EpubFile::open($builder->buildEpub($this->workDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'), limits: $limits), $books);
            $deduplicated[] = (new Merger())->merge($opened, new MergeOptions(), $this->workDir . '/merged-' . bin2hex(random_bytes(4)) . '.epub')->deduplicated;
            array_walk($opened, static fn (EpubFile $book) => $book->close());
        }

        // Under the cap the chapters cannot be parsed, so the stylesheet they name cannot be replaced by the other book's.
        $this->assertSame([1, 0], $deduplicated);
    }

    public function testHtmlSizeBoundaryForConversion(): void
    {
        $chapter = EpubBuilder::xhtml('Chapter', '<p>Text.</p>');
        $directory = EpubBuilder::epub3()->withFile('EPUB/text/chapter.xhtml', $chapter)->writeTo($this->workDir . '/book');

        $this->assertNotEmpty((new EpubDocumentLoader(maxHtmlBytes: strlen($chapter)))->load($directory)->chapters);

        $this->expectException(ConversionException::class);
        (new EpubDocumentLoader(maxHtmlBytes: strlen($chapter) - 1))->load($directory);
    }

    private function book(EpubBuilder $builder): string
    {
        return $builder->buildEpub($this->workDir . '/book-' . bin2hex(random_bytes(4)) . '.epub');
    }

    /**
     * @param \Closure(ZipArchive): int $read
     */
    private function zipValue(string $path, \Closure $read): int
    {
        $zip = new ZipArchive();
        $zip->open($path);
        $value = $read($zip);
        $zip->close();

        return $value;
    }
}
