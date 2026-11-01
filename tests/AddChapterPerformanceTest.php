<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PHPUnit\Framework\TestCase;

/**
 * Test for BUG-04: addChapter() should be linear, not O(n^2)
 */
final class AddChapterPerformanceTest extends TestCase
{
    public function testAddChapterPerformance(): void
    {
        // Create a new book
        $path = sys_get_temp_dir() . '/test_add_chapter_performance.epub';
        $epub = EpubFile::create($path, 'Test Book', 'en');

        // Add 100 chapters and measure the time
        $start = microtime(true);
        for ($i = 1; $i <= 100; $i++) {
            $epub->addChapter("Chapter {$i}", "<p>Content of chapter {$i}</p>");
        }
        $time = microtime(true) - $start;

        // The first 100 chapters should take less than 5 seconds
        // Before the fix, this would take much longer due to O(n^2) behavior
        $this->assertLessThan(5.0, $time, "Adding 100 chapters took {$time} seconds, expected < 5s");

        // Verify all chapters were added
        $manifest = $epub->getManifest();
        $chapterItems = array_filter(
            $manifest->getItems(),
            fn($item) => str_contains($item->path, 'chapter-')
        );
        $this->assertCount(100, $chapterItems);

        // Verify TOC has all entries
        $toc = $epub->getTableOfContents();
        $entries = $toc->getEntries();
        $this->assertCount(100, $entries);

        @unlink($path);
        $epub->close();
    }

    public function testAddChaptersBatch(): void
    {
        // Test the batch add method
        $path = sys_get_temp_dir() . '/test_add_chapters_batch.epub';
        $epub = EpubFile::create($path, 'Test Book Batch', 'en');

        // Add 50 chapters in a batch
        $chapters = [];
        for ($i = 1; $i <= 50; $i++) {
            $chapters[] = ["Chapter {$i}", "<p>Content of chapter {$i}</p>"];
        }

        $start = microtime(true);
        $items = $epub->addChapters($chapters);
        $time = microtime(true) - $start;

        $this->assertLessThan(2.0, $time, "Adding 50 chapters in batch took {$time} seconds, expected < 2s");

        // Verify all chapters were added
        $this->assertCount(50, $items);

        $manifest = $epub->getManifest();
        $chapterItems = array_filter(
            $manifest->getItems(),
            fn($item) => str_contains($item->path, 'chapter-')
        );
        $this->assertCount(50, $chapterItems);

        // Verify spine has all chapters
        $spine = $epub->getSpine()->get();
        $chapterSpineItems = array_filter(
            $spine,
            fn($id) => str_contains($id, 'chapter-')
        );
        $this->assertCount(50, $chapterSpineItems);

        // Verify TOC has all entries
        $toc = $epub->getTableOfContents();
        $entries = $toc->getEntries();
        $this->assertCount(50, $entries);

        @unlink($path);
        $epub->close();
    }

    public function testAddChaptersWritesCompletedTocEntriesWhenLaterChapterFails(): void
    {
        $path = sys_get_temp_dir() . '/test_add_chapters_partial_failure.epub';
        $epub = EpubFile::create($path, 'Test Book Batch Failure', 'en');

        try {
            $epub->addChapters([
                ['First chapter', '<p>Valid content</p>'],
                ['Invalid' . "\x01", '<p>Invalid title</p>'],
            ]);
            $this->fail('Invalid chapter text must be refused');
        } catch (Exception) {
            $entries = $epub->getTableOfContents()->getEntries();

            $this->assertCount(1, $entries);
            $this->assertSame('First chapter', $entries[0]->title);
        } finally {
            $epub->close();
            @unlink($path);
        }
    }

    public function testAddChaptersWritesCompletedTocEntriesWhenIteratorFails(): void
    {
        $path = sys_get_temp_dir() . '/test_add_chapters_iterator_failure.epub';
        $epub = EpubFile::create($path, 'Test Book Iterator Failure', 'en');
        $chapters = static function (): \Generator {
            yield ['First chapter', '<p>Valid content</p>'];
            throw new \RuntimeException('Chapter iteration failed');
        };

        try {
            try {
                $epub->addChapters($chapters());
                $this->fail('The iterator failure must escape');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Chapter iteration failed', $exception->getMessage());
                $entries = $epub->getTableOfContents()->getEntries();

                $this->assertCount(1, $entries);
                $this->assertSame('First chapter', $entries[0]->title);
            }
        } finally {
            $epub->close();
            @unlink($path);
        }
    }

    public function testNextChapterNumberIsTracked(): void
    {
        $path = sys_get_temp_dir() . '/test_next_chapter_number.epub';
        $epub = EpubFile::create($path, 'Test Book Next Chapter', 'en');

        // Add first chapter - should be chapter-1.xhtml
        $item1 = $epub->addChapter("Chapter 1", "<p>Content 1</p>");
        $this->assertStringContainsString('chapter-1.xhtml', $item1->path);

        // Add second chapter - should be chapter-2.xhtml (not chapter-1.xhtml again)
        $item2 = $epub->addChapter("Chapter 2", "<p>Content 2</p>");
        $this->assertStringContainsString('chapter-2.xhtml', $item2->path);

        // Add third chapter with explicit path - should be chapter-3.xhtml
        $item3 = $epub->addChapter("Chapter 3", "<p>Content 3</p>");
        $this->assertStringContainsString('chapter-3.xhtml', $item3->path);

        @unlink($path);
        $epub->close();
    }
}
