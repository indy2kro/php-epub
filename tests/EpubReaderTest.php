<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\EpubReader;
use PhpEpub\Exception;
use PhpEpub\InvalidEpubException;
use PhpEpub\Limits;
use PhpEpub\ManifestItem;
use PhpEpub\ReadOnlyException;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\XmlException;
use PhpEpub\ZipException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class EpubReaderTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_reader_' . bin2hex(random_bytes(8));
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->workDir);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (glob(__DIR__ . '/fixtures/valid*.epub') ?: [] as $fixture) {
            yield basename($fixture) => [$fixture];
        }
    }

    #[DataProvider('fixtures')]
    public function testReadsTheSameAsTheExtractingMode(string $fixture): void
    {
        $file = EpubFile::open($fixture);
        $reader = EpubReader::open($fixture, Limits::web());

        $this->assertSame($file->getMetadata()->getTitle(), $reader->getMetadata()->getTitle());
        $this->assertSame($file->getMetadata()->getAuthors(), $reader->getMetadata()->getAuthors());
        $this->assertSame($file->getMetadata()->getLanguage(), $reader->getMetadata()->getLanguage());
        $this->assertSame($file->getMetadata()->getVersion(), $reader->getMetadata()->getVersion());
        $this->assertSame($file->getMetadata()->getUniqueIdentifier(), $reader->getMetadata()->getUniqueIdentifier());
        $this->assertEquals($file->getManifest()->getItems(), $reader->getManifest()->getItems());
        $this->assertEquals($file->getSpine()->getItems(), $reader->getSpine()->getItems());
        $this->assertEquals($file->getTableOfContents()->getEntries(), $reader->getTableOfContents()->getEntries());
        $this->assertEquals($file->getTableOfContents()->getLandmarks(), $reader->getTableOfContents()->getLandmarks());
        $this->assertEquals($file->getTableOfContents()->getPageList(), $reader->getTableOfContents()->getPageList());
        $this->assertEquals($file->getCoverImage(), $reader->getCoverImage());
        $this->assertSame($file->getText(), $reader->getText());
        $this->assertSame($file->getText(false), $reader->getText(false));
        $this->assertSame($file->getContentManager()->getContentPaths(), $reader->getContentPaths());

        $cover = $reader->getCoverImage();
        if ($cover instanceof ManifestItem) {
            $this->assertSame($file->getContentManager()->getContent($cover->path), $reader->getContent($cover->path));
        }

        $file->close();
        $reader->close();
    }

    #[DataProvider('fixtures')]
    public function testStringAndStreamSourcesMatch(string $fixture): void
    {
        $data = (string) file_get_contents($fixture);
        $expected = EpubReader::open($fixture)->getText();

        $this->assertSame($expected, EpubReader::openString($data)->getText());
        $this->assertSame($expected, EpubReader::fromString($data, null, null, true)->getText());

        $stream = $this->stream($data);
        $this->assertSame($expected, EpubReader::openStream($stream)->getText());
        fclose($stream);
    }

    public function testNothingIsExtractedToDisk(): void
    {
        $marker = 'Marker ' . bin2hex(random_bytes(8));
        $builder = EpubBuilder::epub3();
        $builder->withFile('EPUB/package.opf', str_replace('Valid Book', $marker, (string) $builder->getFile('EPUB/package.opf')));
        $path = $builder->buildEpub($this->workDir . '/book.epub');

        $reader = EpubReader::open($path);
        $this->assertSame($marker, $reader->getMetadata()->getTitle());
        $reader->getTableOfContents()->getEntries();
        $reader->getText();
        $reader->getCoverImage();

        $this->assertSame([], $this->extractionsContaining($marker));
        $reader->close();
    }

    public function testScratchFilesAreRemovedByCloseAndByFailures(): void
    {
        $data = EpubBuilder::epub3()->withFile('EPUB/unique.txt', bin2hex(random_bytes(16)))->buildEpub($this->workDir . '/book.epub');
        $data = (string) file_get_contents($data);

        $reader = EpubReader::fromString($data, null, null, true);
        $this->assertCount(1, $this->scratchArchives($data));
        $reader->close();
        $reader->close();
        $this->assertSame([], $this->scratchArchives($data));

        $stream = $this->stream($data);
        $reader = EpubReader::openStream($stream);
        $this->assertCount(1, $this->scratchArchives($data));
        unset($reader);
        $this->assertSame([], $this->scratchArchives($data));

        $hostile = (string) file_get_contents(EpubBuilder::epub3()->withoutFile('META-INF/container.xml')->withFile('EPUB/unique.txt', bin2hex(random_bytes(16)))->buildEpub($this->workDir . '/hostile.epub'));
        foreach (
            [
            static fn () => EpubReader::fromString($hostile, null, null, true),
            static fn () => EpubReader::fromString($hostile, new Limits(maxEntries: 1), null, true),
            ] as $open
        ) {
            try {
                $open();
                $this->fail('Expected an exception.');
            } catch (Exception) {
                $this->assertSame([], $this->scratchArchives($hostile));
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testInvalidDataIsAZipExceptionOnEveryPath(): void
    {
        $paths = [true];
        if (method_exists(ZipArchive::class, 'openString')) {
            $paths[] = false;
        }

        foreach ($paths as $buffer) {
            try {
                EpubReader::fromString('not a zip', null, null, $buffer);
                $this->fail('Expected a ZipException.');
            } catch (ZipException) {
            }
        }
    }

    public function testReadsInPlaceWhereZipArchiveCanOpenStrings(): void
    {
        if (! method_exists(ZipArchive::class, 'openString')) {
            $this->markTestSkipped('ZipArchive::openString() is not available.');
        }

        $data = (string) file_get_contents(__DIR__ . '/fixtures/valid.epub');
        $this->assertSame(EpubReader::open(__DIR__ . '/fixtures/valid.epub')->getText(), EpubReader::fromString($data, null, null, false)->getText());
    }

    public function testAnInvalidStreamIsAZipExceptionAndLeavesNoScratchFile(): void
    {
        $data = 'not a zip ' . bin2hex(random_bytes(16));

        try {
            EpubReader::openStream($this->stream($data));
            $this->fail('Expected a ZipException.');
        } catch (ZipException) {
            $this->assertSame([], $this->scratchArchives($data));
        }
    }

    public function testChangesAreRefused(): void
    {
        $reader = EpubReader::open(EpubBuilder::epub3()->buildEpub($this->workDir . '/book.epub'));

        $toc = $reader->getTableOfContents();
        $this->assertNotEmpty($toc->getEntries());
        foreach (
            [
            fn () => $reader->getMetadata()->save(),
            fn () => $toc->setEntries([new TocEntry('A', 'EPUB/text/chapter.xhtml')]),
            fn () => $toc->addEntry(new TocEntry('A', 'EPUB/text/chapter.xhtml')),
            fn () => $toc->generateFromHeadings(),
            fn () => $toc->setLandmarks([]),
            fn () => $toc->createNavigation('Title', 'en'),
            ] as $change
        ) {
            try {
                $change();
                $this->fail('Expected a ReadOnlyException.');
            } catch (ReadOnlyException $exception) {
                $this->assertStringContainsString('read-only', $exception->getMessage());
            }
        }
    }

    public function testAClosedReaderIsUnusable(): void
    {
        $reader = EpubReader::open(EpubBuilder::epub3()->buildEpub($this->workDir . '/book.epub'));
        $reader->close();
        $reader->close();

        foreach ([$reader->getMetadata(...), $reader->getText(...), $reader->getContentPaths(...), $reader->getTableOfContents(...)] as $use) {
            try {
                $use();
                $this->fail('Expected an exception.');
            } catch (Exception $exception) {
                $this->assertStringContainsString('closed', $exception->getMessage());
            }
        }

        $reader = EpubReader::open(EpubBuilder::epub3()->buildEpub($this->workDir . '/other.epub'));
        $this->expectException(Exception::class);
        $copy = clone $reader;
        unset($copy);
    }

    public function testMissingAndInvalidInputs(): void
    {
        try {
            EpubReader::open($this->workDir . '/missing.epub');
            $this->fail('Expected a ZipException.');
        } catch (ZipException) {
            $this->addToAssertionCount(1);
        }

        file_put_contents($this->workDir . '/junk.epub', 'not a zip');
        try {
            EpubReader::open($this->workDir . '/junk.epub');
            $this->fail('Expected a ZipException.');
        } catch (ZipException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(XmlException::class);
        EpubReader::open(EpubBuilder::epub3()->withoutFile('META-INF/container.xml')->buildEpub($this->workDir . '/nocontainer.epub'));
    }

    public function testReadingMissingContentFails(): void
    {
        $reader = EpubReader::open(EpubBuilder::epub3()->buildEpub($this->workDir . '/book.epub'));
        $this->assertTrue($reader->hasContent('EPUB/text/chapter.xhtml'));
        $this->assertTrue($reader->hasContent('EPUB/text/../text/chapter.xhtml'));
        $this->assertFalse($reader->hasContent('EPUB/missing.xhtml'));
        $this->assertFalse($reader->hasContent('../outside'));

        $this->expectException(InvalidEpubException::class);
        $reader->getContent('EPUB/missing.xhtml');
    }

    public function testHostileEntryNamesAreRefused(): void
    {
        $this->expectException(ZipException::class);
        EpubReader::open(EpubBuilder::epub3()->withFile('../evil.txt', 'x')->buildEpub($this->workDir . '/evil.epub'));
    }

    public function testNamesDifferingOnlyInCaseAreRefused(): void
    {
        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('differ only in case');
        EpubReader::open(EpubBuilder::epub3()->withFile('EPUB/Text.txt', 'a')->withFile('EPUB/text.txt', 'b')->buildEpub($this->workDir . '/case.epub'));
    }

    public function testEntryCountBoundary(): void
    {
        $path = EpubBuilder::epub3()->buildEpub($this->workDir . '/book.epub');
        $entries = $this->entryCount($path);

        EpubReader::open($path, new Limits(maxEntries: $entries))->close();

        $this->expectException(ZipException::class);
        EpubReader::open($path, new Limits(maxEntries: $entries - 1));
    }

    public function testTotalSizeBoundary(): void
    {
        $path = EpubBuilder::epub3()->buildEpub($this->workDir . '/book.epub');
        $zip = new ZipArchive();
        $zip->open($path);
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $total += (int) ($zip->statIndex($index)['size'] ?? 0);
        }
        $zip->close();

        EpubReader::open($path, new Limits(maxUncompressedBytes: $total))->close();

        $this->expectException(ZipException::class);
        EpubReader::open($path, new Limits(maxUncompressedBytes: $total - 1));
    }

    public function testCompressionRatioBoundary(): void
    {
        $path = EpubBuilder::epub3()->withFile('EPUB/big.bin', str_repeat("\0", 2 * 1024 * 1024))->buildEpub($this->workDir . '/book.epub');
        $zip = new ZipArchive();
        $zip->open($path);
        $compressed = (int) ($zip->statName('EPUB/big.bin')['comp_size'] ?? 1);
        $zip->close();
        $ratio = (int) ceil(2 * 1024 * 1024 / $compressed);

        $reader = EpubReader::open($path, new Limits(maxCompressionRatio: $ratio));
        $this->assertSame(2 * 1024 * 1024, strlen($reader->getContent('EPUB/big.bin')));

        $reader = EpubReader::open($path, new Limits(maxCompressionRatio: $ratio - 1));
        $this->expectException(ZipException::class);
        $reader->getContent('EPUB/big.bin');
    }

    public function testEntriesCountOnceTowardsTheTotal(): void
    {
        $content = bin2hex(random_bytes(500));
        $path = EpubBuilder::epub3()->withFile('EPUB/data.txt', $content)->buildEpub($this->workDir . '/book.epub');
        $zip = new ZipArchive();
        $zip->open($path);
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $total += (int) ($zip->statIndex($index)['size'] ?? 0);
        }
        $zip->close();

        // Everything read, and then the largest entry again: still within the total.
        $reader = EpubReader::open($path, new Limits(maxUncompressedBytes: $total));
        foreach ($reader->getContentPaths() as $contentPath) {
            $reader->getContent($contentPath);
        }
        foreach ([1, 2] as $ignored) {
            $this->assertSame($content, $reader->getContent('EPUB/data.txt'));
        }
    }

    public function testContentSizeCap(): void
    {
        $path = EpubBuilder::epub3()->withFile('EPUB/data.txt', str_repeat('a', 100))->buildEpub($this->workDir . '/book.epub');
        $reader = EpubReader::open($path);

        $this->assertSame(100, strlen($reader->getContent('EPUB/data.txt', 100)));

        $this->expectException(InvalidEpubException::class);
        $reader->getContent('EPUB/data.txt', 99);
    }

    public function testXmlSizeBoundary(): void
    {
        $builder = EpubBuilder::epub3();
        $opf = str_replace('</package>', '<!--' . str_repeat('x', 3000) . '--></package>', (string) $builder->getFile('EPUB/package.opf'));
        $path = $builder->withFile('EPUB/package.opf', $opf)->buildEpub($this->workDir . '/book.epub');

        EpubReader::open($path, new Limits(maxXmlBytes: strlen($opf)))->close();

        $this->expectException(XmlException::class);
        EpubReader::open($path, new Limits(maxXmlBytes: strlen($opf) - 1));
    }

    public function testNavigationXmlSizeBoundary(): void
    {
        $builder = EpubBuilder::epub3();
        $nav = str_replace('</body>', '<!--' . str_repeat('x', 3000) . '--></body>', (string) $builder->getFile('EPUB/nav.xhtml'));
        $path = $builder->withFile('EPUB/nav.xhtml', $nav)->buildEpub($this->workDir . '/book.epub');
        $opfSize = strlen((string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $this->assertGreaterThan($opfSize, strlen($nav));

        $reader = EpubReader::open($path, new Limits(maxXmlBytes: strlen($nav)));
        $this->assertNotEmpty($reader->getTableOfContents()->getEntries());

        $reader = EpubReader::open($path, new Limits(maxXmlBytes: strlen($nav) - 1));
        $this->expectException(XmlException::class);
        $reader->getTableOfContents()->getEntries();
    }

    public function testHtmlSizeBoundaryForText(): void
    {
        $chapter = EpubBuilder::xhtml('Chapter', '<p>' . str_repeat('word ', 200) . '</p>');
        $path = EpubBuilder::epub3()->withFile('EPUB/text/chapter.xhtml', $chapter)->buildEpub($this->workDir . '/book.epub');

        $reader = EpubReader::open($path, new Limits(maxHtmlBytes: strlen($chapter)));
        $this->assertStringContainsString('word', $reader->getText()['EPUB/text/chapter.xhtml']);

        $reader = EpubReader::open($path, new Limits(maxHtmlBytes: strlen($chapter) - 1));
        $this->expectException(InvalidEpubException::class);
        $reader->getText();
    }

    public function testAnOversizedCoverPageHasNoFirstImage(): void
    {
        $page = EpubBuilder::xhtml('Cover', '<img src="../images/cover.png" alt=""/>');
        $builder = EpubBuilder::epub2()
            ->withFile('OEBPS/text/cover.xhtml', $page)
            ->withFile('OEBPS/images/cover.png', (string) base64_decode(EpubBuilder::PNG));
        $opf = (string) $builder->getFile('OEBPS/content.opf');
        $opf = str_replace('</manifest>', '<item id="cp" href="text/cover.xhtml" media-type="application/xhtml+xml"/><item id="ci" href="images/cover.png" media-type="image/png"/></manifest>', $opf);
        $opf = str_replace('</guide>', '<reference type="cover" title="Cover" href="text/cover.xhtml"/></guide>', $opf);
        $path = $builder->withFile('OEBPS/content.opf', $opf)->buildEpub($this->workDir . '/book.epub');

        $this->assertSame('OEBPS/images/cover.png', EpubReader::open($path, new Limits(maxHtmlBytes: strlen($page)))->getCoverImage()?->path);
        $this->assertNull(EpubReader::open($path, new Limits(maxHtmlBytes: strlen($page) - 1))->getCoverImage());
    }
    /**
     * @return resource
     */
    private function stream(string $data)
    {
        $stream = fopen('php://memory', 'w+b') ?: throw new \RuntimeException('No memory stream');
        fwrite($stream, $data);
        rewind($stream);

        return $stream;
    }

    private function entryCount(string $path): int
    {
        $zip = new ZipArchive();
        $zip->open($path);
        $count = $zip->numFiles;
        $zip->close();

        return $count;
    }

    /**
     * The epub_* extraction directories in the system temp directory whose package document holds $marker.
     *
     * @return list<string>
     */
    private function extractionsContaining(string $marker): array
    {
        $found = [];
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_*') ?: [] as $directory) {
            foreach (glob($directory . '/*/*.opf') ?: [] as $opf) {
                if (str_contains((string) file_get_contents($opf), $marker)) {
                    $found[] = $directory;
                }
            }
        }

        return $found;
    }

    /**
     * The scratch archives in the system temp directory that hold exactly $data.
     *
     * @return list<string>
     */
    private function scratchArchives(string $data): array
    {
        $found = [];
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epubio_*' . DIRECTORY_SEPARATOR . 'book.epub') ?: [] as $archive) {
            if (@file_get_contents($archive) === $data) {
                $found[] = $archive;
            }
        }

        return $found;
    }

    /**
     * @return iterable<string, array{EpubBuilder}>
     */
    public static function drmBooks(): iterable
    {
        $encryption = static fn (string $algorithm): string => '<encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#">'
            . '<enc:EncryptedData><enc:EncryptionMethod Algorithm="' . $algorithm . '"/>'
            . '<enc:CipherData><enc:CipherReference URI="EPUB/text/chapter.xhtml"/></enc:CipherData></enc:EncryptedData></encryption>';

        yield 'plain' => [EpubBuilder::epub3()];
        yield 'encrypted resource' => [EpubBuilder::epub3()->withFile('META-INF/encryption.xml', $encryption('http://www.w3.org/2001/04/xmlenc#aes128-cbc'))];
        yield 'font obfuscation only' => [EpubBuilder::epub3()->withFile('META-INF/encryption.xml', $encryption('http://www.idpf.org/2008/embedding'))];
        yield 'lcp license' => [EpubBuilder::epub3()->withFile('META-INF/license.lcpl', '{"id":"x"}')];
        yield 'adept rights' => [EpubBuilder::epub3()->withFile('META-INF/rights.xml', '<rights/>')];
        yield 'unreadable encryption.xml' => [EpubBuilder::epub3()->withFile('META-INF/encryption.xml', '<encryption')];
    }

    #[DataProvider('drmBooks')]
    public function testDrmStateMatchesTheExtractingMode(EpubBuilder $builder): void
    {
        $path = $builder->buildEpub($this->workDir . '/drm.epub');
        $file = EpubFile::open($path);
        $reader = EpubReader::open($path);

        $this->assertSame($file->isDrmProtected(), $reader->isDrmProtected());
        $this->assertSame($file->getEncryptedPaths(), $reader->getEncryptedPaths());
        $this->assertSame($file->toArray(), $reader->toArray());
    }

    #[DataProvider('fixtures')]
    public function testToArrayMatchesTheExtractingMode(string $fixture): void
    {
        $this->assertSame(EpubFile::open($fixture)->toArray(), EpubReader::open($fixture, Limits::web())->toArray());
    }

    public function testToArrayOfAnEncryptedBookIsJsonReady(): void
    {
        $path = EpubBuilder::epub3()->withFile('META-INF/license.lcpl', '{}')->buildEpub($this->workDir . '/lcp.epub');

        $summary = EpubReader::open($path)->toArray();

        $this->assertSame(['isDrmProtected' => true, 'encryptedPathCount' => 0], $summary['drm']);
        $this->assertJson(json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function testGetContentSizeIsTheUncompressedSize(): void
    {
        $path = EpubBuilder::epub3()->withFile('EPUB/size.txt', str_repeat('a', 1000))->buildEpub($this->workDir . '/size.epub');
        $reader = EpubReader::open($path);

        $this->assertSame(1000, $reader->getContentSize('EPUB/size.txt'));

        $this->expectException(InvalidEpubException::class);
        $reader->getContentSize('EPUB/missing.txt');
    }

    public function testADocumentOverTheSizeLimitIsSkippedInTheWordCount(): void
    {
        $path = EpubBuilder::epub3()->buildEpub($this->workDir . '/limited.epub');

        $summary = EpubReader::open($path, new Limits(maxHtmlBytes: 10))->toArray();

        $this->assertSame(0, $summary['stats']['wordCount']);
    }

    public function testDrmAndSummaryNeedAnOpenReader(): void
    {
        $reader = EpubReader::open(EpubBuilder::epub3()->buildEpub($this->workDir . '/closed.epub'));
        $reader->close();

        $this->expectException(Exception::class);
        $reader->toArray();
    }
}
