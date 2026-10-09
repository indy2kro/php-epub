<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * Manifest::addMany(), Manifest::setFallback() and Spine::addMany(): batch edits for large books.
 */
final class BatchEditTest extends TestCase
{
    private string $tmpDir;

    private EpubFile $book;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'batch';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }

        $this->book = EpubFile::open(EpubBuilder::epub3()->buildEpub($this->tmpDir . DIRECTORY_SEPARATOR . 'book.epub'));
    }

    protected function tearDown(): void
    {
        $this->book->cleanup();
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testManifestAddManyAddsFilesAndRemoteItems(): void
    {
        $manifest = $this->book->getManifest();

        $items = $manifest->addMany([
            ['id' => 'one', 'path' => 'EPUB/text/one.xhtml', 'mediaType' => 'application/xhtml+xml', 'properties' => 'svg scripted'],
            ['id' => 'two', 'path' => 'EPUB/images/two.png'],
            ['id' => 'audio', 'url' => 'https://example.com/a.mp3', 'mediaType' => 'audio/mpeg'],
        ]);

        $this->assertCount(3, $items);
        $this->assertSame('text/one.xhtml', $manifest->get('one')?->href);
        $this->assertSame('svg scripted', $manifest->get('one')->properties);
        $this->assertSame('image/png', $manifest->get('two')?->mediaType);
        $this->assertSame('', $manifest->get('audio')?->path);
        $this->assertSame('one', $manifest->findByPath('EPUB/text/one.xhtml')?->id);
        $this->assertTrue($manifest->isModified());
    }

    public function testManifestAddManyRefusesTakenIdsPathsAndIncompleteSpecs(): void
    {
        $manifest = $this->book->getManifest();

        foreach (
            [
            [['id' => 'chapter', 'path' => 'EPUB/text/new.xhtml']],
            [['id' => 'new', 'path' => 'EPUB/text/chapter.xhtml']],
            [['id' => 'new', 'path' => 'EPUB/a.xhtml'], ['id' => 'new', 'path' => 'EPUB/b.xhtml']],
            [['id' => 'new']],
            [['id' => 'new', 'url' => 'not a url']],
            ] as $specs
        ) {
            try {
                $manifest->addMany($specs);
                $this->fail('Invalid specs must be refused: ' . json_encode($specs));
            } catch (Exception) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSetFallback(): void
    {
        $manifest = $this->book->getManifest();
        $manifest->addMany([['id' => 'odd', 'path' => 'EPUB/odd.xyz', 'mediaType' => 'application/x-odd']]);

        $manifest->setFallback('odd', 'chapter');
        $this->assertSame('chapter', $manifest->getFallback('odd'));

        $manifest->setFallback('odd', null);
        $this->assertNull($manifest->getFallback('odd'));

        $this->expectException(Exception::class);
        $manifest->setFallback('odd', 'missing');
    }

    public function testSpineAddManyAppendsEntriesWithFlagsAndProperties(): void
    {
        $this->book->getManifest()->addMany([
            ['id' => 'one', 'path' => 'EPUB/text/one.xhtml', 'mediaType' => 'application/xhtml+xml'],
            ['id' => 'two', 'path' => 'EPUB/text/two.xhtml', 'mediaType' => 'application/xhtml+xml'],
        ]);
        $spine = $this->book->getSpine();

        $spine->addMany([
            ['idref' => 'one', 'properties' => ['page-spread-left', 'rendition:layout-pre-paginated']],
            ['idref' => 'two', 'linear' => false],
        ]);

        $this->assertSame(['chapter', 'one', 'two'], $spine->get());
        $this->assertSame(['page-spread-left', 'rendition:layout-pre-paginated'], $spine->getItemProperties('one'));
        $items = $spine->getItems();
        $this->assertSame(['page-spread-left', 'rendition:layout-pre-paginated'], $items[1]->properties);
        $this->assertFalse($items[2]->linear);
        $this->assertSame([], $items[0]->properties);
    }

    public function testSpineAddManyChangesNothingWhenAnEntryIsInvalid(): void
    {
        $this->book->getManifest()->addMany([['id' => 'one', 'path' => 'EPUB/text/one.xhtml', 'mediaType' => 'application/xhtml+xml']]);
        $spine = $this->book->getSpine();

        foreach (
            [
            [['idref' => 'one'], ['idref' => 'missing']],
            [['idref' => 'one'], ['idref' => 'one']],
            [['idref' => 'chapter']],
            [['idref' => 'one', 'properties' => ['has space']]],
            ] as $entries
        ) {
            try {
                $spine->addMany($entries);
                $this->fail('Invalid entries must be refused: ' . json_encode($entries));
            } catch (Exception) {
                $this->assertSame(['chapter'], $spine->get());
            }
        }
    }
}
