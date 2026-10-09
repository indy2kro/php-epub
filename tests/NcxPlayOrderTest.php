<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * NCX navPoints with the same content src must share their playOrder (EPUBCheck RSC-005), and the playOrder of the
 * others must increase in document order.
 */
final class NcxPlayOrderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'playorder';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    /**
     * @return list<array{string, int}> [src, playOrder] of every navPoint in document order.
     */
    public static function navPoints(string $ncx): array
    {
        preg_match_all('/<navPoint\b[^>]*\bplayOrder="(\d+)"[^>]*>(?:(?!<navPoint\b).)*?<content\b[^>]*\bsrc="([^"]*)"/s', $ncx, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [$match[2], (int) $match[1]], $matches);
    }

    /**
     * @param list<array{string, int}> $points
     */
    public static function assertPlayOrderRule(array $points): void
    {
        $bySrc = [];
        $last = 0;
        foreach ($points as [$src, $order]) {
            if (isset($bySrc[$src])) {
                self::assertSame($bySrc[$src], $order, "navPoints for {$src} have different playOrder values");
                continue;
            }

            self::assertGreaterThan($last, $order, "The playOrder of {$src} does not increase");
            $bySrc[$src] = $order;
            $last = $order;
        }
    }

    public function testNavPointsForTheSameTargetShareTheirPlayOrder(): void
    {
        $book = EpubFile::open(EpubBuilder::epub2()->buildEpub($this->tmpDir . '/book.epub'));
        $chapter = 'OEBPS/text/chapter.xhtml';

        $book->getTableOfContents()->setEntries([
            new TocEntry('Book', $chapter, null, [
                new TocEntry('First', $chapter),
                new TocEntry('Section', $chapter, 'section', [new TocEntry('Again', $chapter, 'section')]),
            ]),
            new TocEntry('End', $chapter, 'end'),
        ]);

        $ncx = (string) file_get_contents($book->getTempDir() . '/OEBPS/toc.ncx');
        $points = self::navPoints($ncx);
        $this->assertCount(5, $points);
        $this->assertSame([1, 1, 2, 2, 3], array_column($points, 1));
        self::assertPlayOrderRule($points);
        // The ids stay unique although the playOrder values repeat.
        preg_match_all('/<navPoint\b[^>]*\bid="([^"]*)"/', $ncx, $ids);
        $this->assertSame($ids[1], array_values(array_unique($ids[1])));
        $book->cleanup();
    }
}
