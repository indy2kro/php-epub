<?php

declare(strict_types=1);

namespace PhpEpub\Test\Split;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\FontObfuscation;
use PhpEpub\Landmark;
use PhpEpub\Split\SplitPlan;
use PhpEpub\Split\Splitter;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Test\Support\MergeBook;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ValidationIssue;
use PHPUnit\Framework\TestCase;

final class SplitterTest extends TestCase
{
    private const string UID = 'urn:uuid:11111111-1111-4111-8111-111111111111';

    private string $tmpDir;

    /**
     * @var list<EpubFile>
     */
    private array $opened = [];

    protected function setUp(): void
    {
        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'split';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->opened as $book) {
            $book->cleanup();
        }

        $this->opened = [];
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return $this->opened[] = MergeBook::open($builder, $this->tmpDir);
    }

    /**
     * @return list<EpubFile> The parts, opened.
     */
    private function split(EpubFile $book, SplitPlan $plan): array
    {
        $paths = (new Splitter())->split($book, $plan, $this->tmpDir . DIRECTORY_SEPARATOR . 'parts-' . bin2hex(random_bytes(4)));

        return array_map(fn (string $path): EpubFile => $this->opened[] = EpubFile::open($path), $paths);
    }

    /**
     * @return list<string>
     */
    private function errors(EpubFile $book): array
    {
        return array_values(array_map(
            strval(...),
            array_filter($book->validate(), static fn (ValidationIssue $issue): bool => $issue->severity === ValidationIssue::ERROR)
        ));
    }

    /**
     * @return list<string> The paths of the documents in the reading order, without the directories.
     */
    private function chapters(EpubFile $part): array
    {
        $manifest = $part->getManifest();

        return array_values(array_map(
            static fn (string $idref): string => basename($manifest->get($idref)->path ?? '', '.xhtml'),
            $part->getSpine()->get()
        ));
    }

    /**
     * Six chapters with a two-level table of contents: [1, 2], [3, 4], [5, 6].
     */
    private function nestedBook(): EpubFile
    {
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 6));
        $chapter = static fn (int $number): string => "EPUB/text/chapter-{$number}.xhtml";
        $book->getTableOfContents()->setEntries([
            new TocEntry('Part A', $chapter(1), null, [new TocEntry('Chapter 2', $chapter(2))]),
            new TocEntry('Part B', $chapter(3), null, [new TocEntry('Chapter 4', $chapter(4))]),
            new TocEntry('Part C', $chapter(5), null, [new TocEntry('Chapter 6', $chapter(6))]),
        ]);

        return $book;
    }

    public function testSplitsByTopLevelTableOfContentsEntries(): void
    {
        $parts = $this->split($this->nestedBook(), SplitPlan::byToc());

        $this->assertCount(3, $parts);
        $this->assertSame([['chapter-1', 'chapter-2'], ['chapter-3', 'chapter-4'], ['chapter-5', 'chapter-6']], array_map($this->chapters(...), $parts));
        foreach ($parts as $part) {
            $this->assertSame([], $this->errors($part));
        }

        $entries = $parts[1]->getTableOfContents()->getEntries();
        $this->assertSame(['Part B'], array_map(static fn (TocEntry $entry): string => $entry->title, $entries));
        $this->assertSame('EPUB/text/chapter-3.xhtml', $entries[0]->path);
        $this->assertSame('Chapter 4', $entries[0]->children[0]->title);

        // The NCX is not part of this book; a book with one keeps only its own points (see the EPUB 2 test).
        $nav = $parts[1]->getContentManager()->getContent('EPUB/nav.xhtml');
        $this->assertStringNotContainsString('chapter-5', $nav);
        $this->assertStringNotContainsString('chapter-1.xhtml', $nav);
    }

    public function testSplitsByAnInnerTableOfContentsLevel(): void
    {
        $parts = $this->split($this->nestedBook(), SplitPlan::byToc(2));

        // The first part also takes what comes before the first entry of the level.
        $this->assertSame([['chapter-1', 'chapter-2', 'chapter-3'], ['chapter-4', 'chapter-5'], ['chapter-6']], array_map($this->chapters(...), $parts));
        $entries = $parts[0]->getTableOfContents()->getEntries();
        $this->assertSame(['Part A', 'Part B'], array_map(static fn (TocEntry $entry): string => $entry->title, $entries));
        // Part B's own page is in the first part, so its heading stays here without a link, above its child.
        $second = $parts[1]->getTableOfContents()->getEntries();
        $this->assertSame('Part B', $second[0]->title);
        $this->assertSame('', $second[0]->path);
        $this->assertSame('Chapter 4', $second[0]->children[0]->title);
    }

    public function testSplitsEveryNItems(): void
    {
        $parts = $this->split($this->open(MergeBook::builder(self::UID, 'Book', 5)), SplitPlan::everySpineItems(2));

        $this->assertSame([['chapter-1', 'chapter-2'], ['chapter-3', 'chapter-4'], ['chapter-5']], array_map($this->chapters(...), $parts));
        foreach ($parts as $part) {
            $this->assertSame([], $this->errors($part));
        }
    }

    public function testSplitsByExplicitRanges(): void
    {
        $parts = $this->split($this->open(MergeBook::builder(self::UID, 'Book', 6)), SplitPlan::bySpineRanges([[0, 1], [3, 4]]));

        $this->assertSame([['chapter-1', 'chapter-2'], ['chapter-4', 'chapter-5']], array_map($this->chapters(...), $parts));
        $this->assertSame('Book (Part 2 of 2)', $parts[1]->getMetadata()->getTitle());
    }

    public function testRejectsRangesThatDoNotFit(): void
    {
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 3));

        try {
            $this->split($book, SplitPlan::bySpineRanges([[0, 3]]));
            $this->fail('A range beyond the reading order must be refused');
        } catch (Exception $exception) {
            $this->assertStringContainsString('beyond the reading order', $exception->getMessage());
        }

        foreach ([[[2, 1]], [[-1, 1]], [[0, 2], [2, 3]], [[3, 4], [0, 1]], []] as $ranges) {
            try {
                SplitPlan::bySpineRanges($ranges);
                $this->fail('Invalid ranges must be refused: ' . json_encode($ranges));
            } catch (Exception) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSplitsByApproximateSize(): void
    {
        $body = '<p>' . str_repeat('word ', 800) . '</p>';
        $bodies = array_fill(1, 6, $body);
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 6, chapterBodies: $bodies));
        $chapterBytes = (int) filesize($book->getTempDir() . '/EPUB/text/chapter-1.xhtml');
        $stylesheetBytes = strlen('p { margin: 0; }');

        // Two chapters and the shared stylesheet fit; a third does not.
        $parts = $this->split($book, SplitPlan::byMaxBytes(2 * $chapterBytes + $stylesheetBytes + 10));

        $this->assertSame([['chapter-1', 'chapter-2'], ['chapter-3', 'chapter-4'], ['chapter-5', 'chapter-6']], array_map($this->chapters(...), $parts));

        // An item above the limit still gets a part of its own.
        $oversized = $this->split($book, SplitPlan::byMaxBytes(1));
        $this->assertCount(6, $oversized);
    }

    public function testPartsKeepOnlyTheFilesTheirDocumentsUse(): void
    {
        $items = '<item id="pic1" href="images/one.png" media-type="image/png"/>'
            . '<item id="pic3" href="images/three.png" media-type="image/png"/>'
            . '<item id="orphan" href="images/orphan.png" media-type="image/png"/>';
        $files = ['EPUB/images/one.png' => 'one', 'EPUB/images/three.png' => 'three', 'EPUB/images/orphan.png' => 'orphan'];
        $bodies = [1 => '<p><img src="../images/one.png" alt=""/></p>', 3 => '<p><img src="../images/three.png" alt=""/></p>'];
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 4, $items, $files, chapterBodies: $bodies));

        $parts = $this->split($book, SplitPlan::everySpineItems(2));

        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $parts[0]->getManifest()->findByPath('EPUB/images/one.png'));
        $this->assertNull($parts[0]->getManifest()->findByPath('EPUB/images/three.png'));
        $this->assertFileDoesNotExist($parts[0]->getTempDir() . '/EPUB/images/three.png');
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $parts[1]->getManifest()->findByPath('EPUB/images/three.png'));
        $this->assertNull($parts[1]->getManifest()->findByPath('EPUB/images/one.png'));
        foreach ($parts as $part) {
            $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $part->getManifest()->findByPath('EPUB/css/style.css'));
            $this->assertNull($part->getManifest()->findByPath('EPUB/images/orphan.png'));
            $this->assertNull($part->getManifest()->get('chapter-9'));
            $this->assertSame([], $this->errors($part));
        }
    }

    public function testLinksToOtherPartsBecomePlainText(): void
    {
        $bodies = [
            1 => '<p>Go to <a href="chapter-2.xhtml#x">the next chapter</a> or <a href="chapter-3.xhtml#y"><em>far away</em></a> or <a href="#here">here</a>.</p>',
            3 => '<p>Back to <a href="chapter-1.xhtml">the start</a>.</p>',
        ];
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 4, chapterBodies: $bodies));

        $parts = $this->split($book, SplitPlan::everySpineItems(2));

        $first = $parts[0]->getContentManager()->getContent('EPUB/text/chapter-1.xhtml');
        $this->assertStringContainsString('<a href="chapter-2.xhtml#x">the next chapter</a>', $first);
        $this->assertStringNotContainsString('chapter-3.xhtml', $first);
        $this->assertStringContainsString('<em>far away</em>', $first);
        $this->assertStringContainsString('<a href="#here">here</a>', $first);

        $second = $parts[1]->getContentManager()->getContent('EPUB/text/chapter-3.xhtml');
        $this->assertStringNotContainsString('chapter-1.xhtml', $second);
        $this->assertStringContainsString('Back to the start.', $second);
        foreach ($parts as $part) {
            $this->assertSame([], $this->errors($part));
        }
    }

    public function testEveryPartGetsAnIdentifierAndTitleOfItsOwn(): void
    {
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 4, metadata: '<dc:identifier>urn:isbn:9780000000002</dc:identifier>'));

        $parts = $this->split($book, SplitPlan::everySpineItems(2));

        $identifiers = array_map(static fn (EpubFile $part): ?string => $part->getMetadata()->getUniqueIdentifier(), $parts);
        $this->assertCount(2, array_unique($identifiers));
        $this->assertNotContains(self::UID, $identifiers);
        foreach ($parts as $part) {
            $this->assertMatchesRegularExpression('/^urn:uuid:[0-9a-f-]{36}$/', (string) $part->getMetadata()->getUniqueIdentifier());
            $this->assertCount(1, $part->getMetadata()->getIdentifiers());
            $this->assertSame(['Author of Book'], $part->getMetadata()->getAuthors());
        }

        $this->assertSame(['Book (Part 1 of 2)', 'Book (Part 2 of 2)'], array_map(static fn (EpubFile $part): string => $part->getMetadata()->getTitle(), $parts));
        $this->assertSame(self::UID, $book->getMetadata()->getUniqueIdentifier());
        $this->assertSame('Book', $book->getMetadata()->getTitle());
    }

    public function testTitlePatternFilePrefixAndClockAreConfigurable(): void
    {
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 4));
        $plan = SplitPlan::everySpineItems(2)
            ->withTitlePattern('{title}, volume {n}/{total}')
            ->withFilePrefix('volume')
            ->withClock(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2031-02-03 04:05:06', new \DateTimeZone('UTC')));

        $paths = (new Splitter())->split($book, $plan, $this->tmpDir . '/named');

        $this->assertSame(['volume-01.epub', 'volume-02.epub'], array_map(basename(...), $paths));
        $part = $this->opened[] = EpubFile::open($paths[1]);
        $this->assertSame('Book, volume 2/2', $part->getMetadata()->getTitle());
        $this->assertSame('2031-02-03T04:05:06Z', $part->getMetadata()->getModifiedDate());
    }

    public function testAnUnsplitBookKeepsItsTitle(): void
    {
        $parts = $this->split($this->open(MergeBook::builder(self::UID, 'Book', 2)), SplitPlan::everySpineItems(5));

        $this->assertCount(1, $parts);
        $this->assertSame('Book', $parts[0]->getMetadata()->getTitle());
    }

    public function testObfuscatedFontsAreRekeyedAndPrunedWithTheirDocuments(): void
    {
        $font = random_bytes(3000);
        $items = '<item id="font" href="fonts/body.otf" media-type="font/otf"/>';
        $css = '@font-face { font-family: F; src: url(../fonts/body.otf) }';
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 2, $items, ['EPUB/fonts/body.otf' => 'placeholder'], $css));
        $book->getContentManager()->addFont('EPUB/fonts/body.otf', $font);
        $saved = $this->tmpDir . '/with-font.epub';
        $book->save($saved);
        $withFont = $this->opened[] = EpubFile::open($saved);

        $parts = $this->split($withFont, SplitPlan::everySpineItems(1));

        foreach ($parts as $part) {
            $identifier = (string) $part->getMetadata()->getUniqueIdentifier();
            $this->assertNotSame(self::UID, $identifier);
            $this->assertSame($font, $part->getContentManager()->getFontData('EPUB/fonts/body.otf'));
            $stored = $part->getContentManager()->getContent('EPUB/fonts/body.otf');
            $this->assertSame($font, FontObfuscation::apply($stored, FontObfuscation::IDPF, (string) FontObfuscation::key(FontObfuscation::IDPF, $identifier)));
            $this->assertSame([], $this->errors($part));
        }
    }

    public function testEncryptionEntriesOfRemovedFontsAreDropped(): void
    {
        $font = random_bytes(2000);
        $items = '<item id="font" href="fonts/only-two.otf" media-type="font/otf"/><item id="two" href="css/two.css" media-type="text/css"/>';
        $files = [
            'EPUB/fonts/only-two.otf' => 'placeholder',
            'EPUB/css/two.css' => '@font-face { font-family: F; src: url(../fonts/only-two.otf) }',
            'EPUB/text/chapter-2.xhtml' => EpubBuilder::xhtml('Two', '<p>Two.</p>', '../css/two.css'),
        ];
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 2, $items, $files));
        $book->getContentManager()->addFont('EPUB/fonts/only-two.otf', $font);
        $saved = $this->tmpDir . '/two-font.epub';
        $book->save($saved);
        $withFont = $this->opened[] = EpubFile::open($saved);

        $parts = $this->split($withFont, SplitPlan::everySpineItems(1));

        $this->assertNull($parts[0]->getManifest()->findByPath('EPUB/fonts/only-two.otf'));
        $this->assertNull($parts[0]->getManifest()->findByPath('EPUB/css/two.css'));
        $this->assertStringNotContainsString('only-two', (string) file_get_contents($parts[0]->getTempDir() . '/META-INF/encryption.xml'));
        $this->assertSame($font, $parts[1]->getContentManager()->getFontData('EPUB/fonts/only-two.otf'));
        $this->assertStringContainsString('only-two', (string) file_get_contents($parts[1]->getTempDir() . '/META-INF/encryption.xml'));
        foreach ($parts as $part) {
            $this->assertSame([], $this->errors($part));
        }
    }

    public function testPageListsAreDropped(): void
    {
        $nav = EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><h1>Contents</h1><ol><li><a href="text/chapter-1.xhtml">One</a></li><li><a href="text/chapter-2.xhtml">Two</a></li></ol></nav>'
            . '<nav epub:type="page-list" hidden="hidden"><ol><li><a href="text/chapter-2.xhtml#p1">1</a></li></ol></nav>'
            . '<nav epub:type="landmarks"><ol><li><a epub:type="bodymatter" href="text/chapter-1.xhtml">Start</a></li><li><a epub:type="endnotes" href="text/chapter-2.xhtml">Notes</a></li></ol></nav>');
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 2, files: ['EPUB/nav.xhtml' => $nav]));

        $parts = $this->split($book, SplitPlan::everySpineItems(1));

        $navigation = $parts[0]->getContentManager()->getContent('EPUB/nav.xhtml');
        $this->assertStringNotContainsString('page-list', $navigation);
        $this->assertStringNotContainsString('chapter-2', $navigation);
        $this->assertSame(['bodymatter'], array_map(static fn (Landmark $landmark): string => $landmark->type, $parts[0]->getTableOfContents()->getLandmarks()));
        $this->assertSame([], $this->errors($parts[0]));
    }

    public function testBooksWithAnNcxKeepOnlyTheirOwnPoints(): void
    {
        $book = $this->opened[] = EpubFile::open(dirname(__DIR__) . '/fixtures/valid_6.epub');

        $parts = $this->split($book, SplitPlan::byToc());

        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            $ncx = array_values(array_filter($part->getManifest()->getItems(), static fn ($item): bool => $item->mediaType === 'application/x-dtbncx+xml'));
            $this->assertCount(1, $ncx);
            $contents = (string) file_get_contents($part->getTempDir() . '/' . $ncx[0]->path);
            preg_match_all('/<content[^>]*src="([^"#]*)/', $contents, $matches);
            $this->assertNotSame([], $matches[1]);
            foreach ($matches[1] as $source) {
                $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $part->getManifest()->findByHref($source), "The NCX points outside the part: {$source}");
            }

            $this->assertSame($ncx[0]->id, $part->getSpine()->getToc());
            $this->assertStringContainsString((string) $part->getMetadata()->getUniqueIdentifier(), $contents);
            $this->assertSame([], $this->errors($part));
        }
    }

    public function testRefusesBooksThatCannotBeSplitAsAsked(): void
    {
        $emptyNav = EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><h1>Contents</h1><ol/></nav>');
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 2, files: ['EPUB/nav.xhtml' => $emptyNav]));

        try {
            $this->split($book, SplitPlan::byToc());
            $this->fail('A book without table of contents entries cannot be split by them');
        } catch (Exception $exception) {
            $this->assertStringContainsString('table of contents', $exception->getMessage());
        }

        $drm = $this->open(MergeBook::builder(self::UID, 'Book', 2, files: ['META-INF/rights.xml' => '<rights/>']));
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('DRM');
        $this->split($drm, SplitPlan::everySpineItems(1));
    }

    public function testTheBookAndItsFileAreNotChanged(): void
    {
        $builder = MergeBook::builder(self::UID, 'Book', 3);
        $path = $builder->buildEpub($this->tmpDir . '/source.epub');
        $before = (string) md5_file($path);
        $book = $this->opened[] = EpubFile::open($path);

        $this->split($book, SplitPlan::everySpineItems(1));

        $this->assertSame($before, md5_file($path));
        $this->assertSame(['chapter1', 'chapter2', 'chapter3'], $book->getSpine()->get());
        $this->assertSame('Book', $book->getMetadata()->getTitle());
        $this->assertSame(self::UID, $book->getMetadata()->getUniqueIdentifier());
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $book->getManifest()->findByPath('EPUB/text/chapter-3.xhtml'));
    }

    public function testRealBooksSplitIntoValidParts(): void
    {
        foreach (['valid.epub', 'valid_2.epub', 'valid_3.epub'] as $name) {
            $book = $this->opened[] = EpubFile::open(dirname(__DIR__) . '/fixtures/' . $name);
            $before = $this->errors($book);

            foreach ([SplitPlan::byToc(), SplitPlan::everySpineItems(5), SplitPlan::byMaxBytes(150_000)] as $plan) {
                foreach ($this->split($book, $plan) as $part) {
                    $this->assertSame($before, $this->errors($part), $name);
                    $this->assertNotSame([], $part->getSpine()->get());
                }
            }
        }
    }

    public function testOtherNavigationsKeepOnlyEntriesOfThePart(): void
    {
        $nav = EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><h1>Contents</h1><ol><li><a href="text/chapter-1.xhtml">One</a></li><li><a href="text/chapter-2.xhtml">Two</a></li></ol></nav>'
            . '<nav epub:type="lot"><h2>Tables</h2><ol><li><a href="text/chapter-1.xhtml">T1</a></li><li><a href="text/chapter-2.xhtml">T2</a></li></ol></nav>'
            . '<nav epub:type="loi"><ol><li><a href="text/chapter-2.xhtml">I2</a><ol><li><a href="text/chapter-2.xhtml#x">I2x</a></li></ol></li></ol></nav>');
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 2, files: ['EPUB/nav.xhtml' => $nav]));

        $parts = $this->split($book, SplitPlan::everySpineItems(1));

        $first = $parts[0]->getContentManager()->getContent('EPUB/nav.xhtml');
        $this->assertStringContainsString('T1', $first);
        $this->assertStringNotContainsString('T2', $first);
        $this->assertStringNotContainsString('I2', $first);
        $this->assertStringNotContainsString('loi', $first);
        $second = $parts[1]->getContentManager()->getContent('EPUB/nav.xhtml');
        $this->assertStringContainsString('T2', $second);
        $this->assertStringNotContainsString('T1', $second);
        $this->assertStringContainsString('I2x', $second);
        foreach ($parts as $part) {
            $this->assertSame([], $this->errors($part));
        }
    }

    public function testRefusesPlansWithTooManyPartsBeforeWritingAnything(): void
    {
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 4));
        $directory = $this->tmpDir . '/too-many';

        try {
            (new Splitter())->split($book, SplitPlan::everySpineItems(1)->withMaxParts(3), $directory);
            $this->fail('A plan above the part limit must be refused');
        } catch (Exception $exception) {
            $this->assertStringContainsString('limit', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertCount(4, (new Splitter())->split($book, SplitPlan::everySpineItems(1)->withMaxParts(4), $directory));
    }

    public function testAFailureLeavesNoPartsBehind(): void
    {
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 3));
        $calls = 0;
        $plan = SplitPlan::everySpineItems(1)->withClock(static function () use (&$calls): \DateTimeImmutable {
            if (++$calls === 2) {
                throw new Exception('The clock broke');
            }

            return new \DateTimeImmutable('2030-01-01');
        });
        $directory = $this->tmpDir . '/failing';

        try {
            (new Splitter())->split($book, $plan, $directory);
            $this->fail('The clock failure must escape');
        } catch (Exception $exception) {
            $this->assertSame('The clock broke', $exception->getMessage());
        }

        $this->assertSame([], glob($directory . '/*.epub'));
    }

    /**
     * Every hyperlink of every document of a part points to a document of the part's reading order.
     */
    private function assertLinksStayInTheReadingOrder(EpubFile $part, string $label): void
    {
        $manifest = $part->getManifest();
        $spine = [];
        foreach ($part->getSpine()->get() as $idref) {
            $spine[$manifest->get($idref)->path ?? ''] = true;
        }

        foreach ($manifest->getItems() as $item) {
            if ($item->mediaType !== 'application/xhtml+xml' || $item->path === '') {
                continue;
            }

            $content = (string) file_get_contents($part->getTempDir() . '/' . $item->path);
            preg_match_all('/<a\b[^>]*\bhref="([^"#]+)/', $content, $matches);
            foreach ($matches[1] as $href) {
                if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1) {
                    continue;
                }

                $directory = dirname($item->path);
                $path = (string) preg_replace('#[^/]+/\.\./#', '', ($directory === '.' ? '' : $directory . '/') . rawurldecode($href));
                $this->assertArrayHasKey($path, $spine, "{$label}: {$item->path} links to {$href}, which is not in the reading order");
            }
        }
    }

    public function testNoPartLinksToADocumentOutsideItsReadingOrder(): void
    {
        foreach (['valid.epub', 'valid_2.epub', 'valid_4.epub', 'valid_6.epub'] as $name) {
            $book = $this->opened[] = EpubFile::open(dirname(__DIR__) . '/fixtures/' . $name);

            foreach ([SplitPlan::everySpineItems(4), SplitPlan::byToc()] as $plan) {
                foreach ($this->split($book, $plan) as $number => $part) {
                    $this->assertLinksStayInTheReadingOrder($part, $name . ' part ' . ($number + 1));
                }
            }
        }
    }

    public function testASelfLinkOfTheNavigationDocumentBecomesTextInPartsThatDoNotListIt(): void
    {
        $nav = EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><h1>Contents</h1><ol><li><a href="text/chapter-1.xhtml">One</a></li><li><a href="text/chapter-2.xhtml">Two</a></li></ol></nav>'
            . '<p>See <a href="nav.xhtml">this page</a> and <a href="text/chapter-2.xhtml">chapter two</a>.</p>');
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 2, files: ['EPUB/nav.xhtml' => $nav]));

        $parts = $this->split($book, SplitPlan::everySpineItems(1));

        $navigation = $parts[0]->getContentManager()->getContent('EPUB/nav.xhtml');
        $this->assertStringNotContainsString('href="nav.xhtml"', $navigation);
        $this->assertStringContainsString('this page', $navigation);
        $this->assertStringNotContainsString('chapter-2', $navigation);
        foreach ($parts as $number => $part) {
            $this->assertLinksStayInTheReadingOrder($part, 'part ' . ($number + 1));
        }
    }

    public function testHeadingsWithoutPagesStartPartsAtTheirFirstLinkedChild(): void
    {
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 4));
        $chapter = static fn (int $number): string => "EPUB/text/chapter-{$number}.xhtml";
        $book->getTableOfContents()->setEntries([
            new TocEntry('Only a heading', ''),
            new TocEntry('Part A', $chapter(1), null, [new TocEntry('Chapter 2', $chapter(2))]),
            new TocEntry('Part B', '', null, [new TocEntry('Chapter 3', $chapter(3))]),
        ]);

        $parts = $this->split($book, SplitPlan::byToc());

        $this->assertSame([['chapter-1', 'chapter-2'], ['chapter-3', 'chapter-4']], array_map($this->chapters(...), $parts));
    }

    public function testBooksWithAnUnreadableNavigationCannotBeSplit(): void
    {
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 2, files: ['EPUB/nav.xhtml' => '<html><body><nav epub:type="toc"><ol><li>']));

        $this->expectException(Exception::class);
        $this->split($book, SplitPlan::everySpineItems(1));
    }

    public function testBooksWithoutNavigationAndGuideAreSplitWithoutLandmarks(): void
    {
        $book = $this->opened[] = EpubFile::open(EpubBuilder::minimal()->buildEpub($this->tmpDir . '/minimal.epub'));

        $parts = $this->split($book, SplitPlan::everySpineItems(1));

        $this->assertCount(1, $parts);
        $this->assertSame(['chapter'], $this->chapters($parts[0]));
    }

    public function testPartsPointTheSpineAtTheNcx(): void
    {
        $ncx = '<?xml version="1.0" encoding="UTF-8"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head><meta name="dtb:uid" content="x"/></head>'
            . '<docTitle><text>Book</text></docTitle><navMap><navPoint id="n1" playOrder="1"><navLabel><text>One</text></navLabel><content src="text/chapter-1.xhtml"/></navPoint></navMap></ncx>';
        $items = '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>';
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 2, $items, ['EPUB/toc.ncx' => $ncx]));
        $this->assertNull($book->getSpine()->getToc());

        $parts = $this->split($book, SplitPlan::everySpineItems(1));

        $this->assertSame('ncx', $parts[0]->getSpine()->getToc());
        $this->assertSame('ncx', $parts[1]->getSpine()->getToc());
    }

    public function testUnreadableEncryptionInfoIsReportedWhenAPartIsSaved(): void
    {
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 2, files: ['META-INF/encryption.xml' => 'this is not xml']));
        $directory = $this->tmpDir . '/broken-encryption';

        try {
            (new Splitter())->split($book, SplitPlan::everySpineItems(1), $directory);
            $this->fail('Saving a part with a new identifier needs a readable encryption.xml');
        } catch (Exception) {
            $this->assertSame([], glob($directory . '/*.epub'));
        }
    }

    public function testNavEntriesWhoseParentsLeaveThePartKeepTheirChildren(): void
    {
        $nav = EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><h1>Contents</h1><ol><li><a href="text/chapter-1.xhtml">One</a></li><li><a href="text/chapter-2.xhtml">Two</a></li></ol></nav>'
            . '<nav epub:type="loi"><ol>'
            . '<li><a href="text/chapter-2.xhtml">Parent elsewhere</a><ol><li><a href="text/chapter-1.xhtml">Child here</a></li></ol></li>'
            . '<li><a href="text/chapter-1.xhtml">Parent here</a><ol><li><a href="text/chapter-2.xhtml">Child elsewhere</a></li></ol></li>'
            . '</ol></nav>');
        $book = $this->open(MergeBook::builder(self::UID, 'Book', 2, files: ['EPUB/nav.xhtml' => $nav]));

        $parts = $this->split($book, SplitPlan::everySpineItems(1));

        $first = $parts[0]->getContentManager()->getContent('EPUB/nav.xhtml');
        $this->assertStringContainsString('<span>Parent elsewhere</span>', $first);
        $this->assertStringContainsString('<a href="text/chapter-1.xhtml">Child here</a>', $first);
        $this->assertStringContainsString('<a href="text/chapter-1.xhtml">Parent here</a>', $first);
        $this->assertStringNotContainsString('Child elsewhere', $first);
        foreach ($parts as $part) {
            $this->assertSame([], $this->errors($part));
        }
    }
}
