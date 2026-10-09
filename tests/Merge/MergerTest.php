<?php

declare(strict_types=1);

namespace PhpEpub\Test\Merge;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\FontObfuscation;
use PhpEpub\Merge\MergeOptions;
use PhpEpub\Merge\Merger;
use PhpEpub\Test\NcxPlayOrderTest;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Test\Support\MergeBook;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ValidationIssue;
use PHPUnit\Framework\TestCase;

final class MergerTest extends TestCase
{
    private const string UID_ONE = 'urn:uuid:11111111-1111-4111-8111-111111111111';

    private const string UID_TWO = 'urn:uuid:22222222-2222-4222-8222-222222222222';

    private const string UID_THREE = 'urn:uuid:33333333-3333-4333-8333-333333333333';

    private string $tmpDir;

    /**
     * @var list<EpubFile>
     */
    private array $opened = [];

    protected function setUp(): void
    {
        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'merge';
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

    private function fixture(string $name): EpubFile
    {
        return $this->opened[] = EpubFile::open(dirname(__DIR__) . '/fixtures/' . $name);
    }

    /**
     * @param list<EpubFile> $books
     */
    private function merge(array $books, ?MergeOptions $options = null): EpubFile
    {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'merged-' . bin2hex(random_bytes(4)) . '.epub';
        (new Merger())->merge($books, $options ?? new MergeOptions(), $path);

        return $this->opened[] = EpubFile::open($path);
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

    private function directoryBytes(EpubFile $book): int
    {
        $bytes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) $book->getTempDir(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $bytes += $file instanceof \SplFileInfo && $file->isFile() ? (int) $file->getSize() : 0;
        }

        return $bytes;
    }

    public function testMergesRealBooksOfBothEpubVersions(): void
    {
        $sources = [$this->fixture('valid.epub'), $this->fixture('valid_1.epub'), $this->fixture('valid_2.epub')];
        $before = array_map($this->errors(...), $sources);
        $spineLength = array_sum(array_map(static fn (EpubFile $book): int => count($book->getSpine()->get()), $sources));

        $merged = $this->merge($sources);

        $this->assertSame([], $this->errors($merged));
        $this->assertSame([[], [], []], $before);
        $this->assertSame('3.0', $merged->getMetadata()->getVersion());
        $this->assertCount($spineLength, $merged->getSpine()->get());
        $this->assertCount(3, $merged->getTableOfContents()->getEntries());
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $merged->getCoverImage());
    }

    public function testBooksWithTheSameFileNamesAndIdsGetTheirOwnDirectories(): void
    {
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 2, '<item id="img" href="images/pic.png" media-type="image/png"/>', ['EPUB/images/pic.png' => 'one-picture']));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two', 2, '<item id="img" href="images/pic.png" media-type="image/png"/>', ['EPUB/images/pic.png' => 'two-picture']));

        $merged = $this->merge([$one, $two]);

        $this->assertSame([], $this->errors($merged));
        $manifest = $merged->getManifest();
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $manifest->findByPath('EPUB/book-01/text/chapter-1.xhtml'));
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $manifest->findByPath('EPUB/book-02/text/chapter-1.xhtml'));
        $this->assertSame('one-picture', $merged->getContentManager()->getContent('EPUB/book-01/images/pic.png'));
        $this->assertSame('two-picture', $merged->getContentManager()->getContent('EPUB/book-02/images/pic.png'));

        $ids = array_map(static fn ($item): string => $item->id, $manifest->getItems());
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame(
            ['b01-chapter1', 'b01-chapter2', 'b02-chapter1', 'b02-chapter2'],
            $merged->getSpine()->get()
        );
    }

    public function testIdenticalStylesheetsAreKeptOnceAndReferencesFollow(): void
    {
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One'));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two'));
        $report = (new Merger())->merge([$one, $two], new MergeOptions(), $this->tmpDir . '/merged.epub');
        $merged = $this->opened[] = EpubFile::open($this->tmpDir . '/merged.epub');

        $this->assertSame(1, $report->deduplicated);
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $merged->getManifest()->findByPath('EPUB/book-01/css/style.css'));
        $this->assertNull($merged->getManifest()->findByPath('EPUB/book-02/css/style.css'));
        $this->assertFileDoesNotExist($merged->getTempDir() . '/EPUB/book-02/css/style.css');
        $this->assertStringContainsString('href="../../book-01/css/style.css"', $merged->getContentManager()->getContent('EPUB/book-02/text/chapter-1.xhtml'));
        $this->assertStringContainsString('href="../css/style.css"', $merged->getContentManager()->getContent('EPUB/book-01/text/chapter-1.xhtml'));
        $this->assertSame([], $this->errors($merged));
    }

    public function testDeduplicationCanBeSwitchedOff(): void
    {
        $merged = $this->merge(
            [$this->open(MergeBook::builder(self::UID_ONE, 'One')), $this->open(MergeBook::builder(self::UID_TWO, 'Two'))],
            new MergeOptions(deduplicate: false)
        );

        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $merged->getManifest()->findByPath('EPUB/book-02/css/style.css'));
        $this->assertStringContainsString('href="../css/style.css"', $merged->getContentManager()->getContent('EPUB/book-02/text/chapter-1.xhtml'));
    }

    public function testStylesheetsThatReferenceOtherFilesAreNotMerged(): void
    {
        $css = 'p { background: url(bg.png) }';
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, '<item id="bg" href="css/bg.png" media-type="image/png"/>', ['EPUB/css/bg.png' => 'first'], $css));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two', 1, '<item id="bg" href="css/bg.png" media-type="image/png"/>', ['EPUB/css/bg.png' => 'second'], $css));

        $merged = $this->merge([$one, $two]);

        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $merged->getManifest()->findByPath('EPUB/book-02/css/style.css'));
        $this->assertSame('second', $merged->getContentManager()->getContent('EPUB/book-02/css/bg.png'));
    }

    public function testADuplicateThatAnUnreadableDocumentMentionsIsKept(): void
    {
        $broken = '<html><body><p>See <img src="../images/pic.png"></p></body></html>';
        $items = '<item id="img" href="images/pic.png" media-type="image/png"/><item id="broken" href="text/broken.xhtml" media-type="application/xhtml+xml"/>';
        $files = ['EPUB/images/pic.png' => 'same-picture'];
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, $items, $files + ['EPUB/text/broken.xhtml' => $broken]));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two', 1, $items, $files + ['EPUB/text/broken.xhtml' => $broken]));

        $merged = $this->merge([$one, $two]);

        // The picture of book two is mentioned by a document that cannot be rewritten, so it stays.
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $merged->getManifest()->findByPath('EPUB/book-02/images/pic.png'));
        $this->assertStringContainsString('../images/pic.png', $merged->getContentManager()->getContent('EPUB/book-02/text/broken.xhtml'));
    }

    public function testObfuscatedFontsAreKeyedForTheMergedIdentifier(): void
    {
        $fontOne = random_bytes(3000);
        $fontTwo = random_bytes(3000);
        $one = $this->bookWithFont(self::UID_ONE, 'One', 'EPUB/fonts/body.otf', $fontOne);
        $two = $this->bookWithFont(self::UID_TWO, 'Two', 'EPUB/fonts/body.otf', $fontTwo);

        $merged = $this->merge([$one, $two], new MergeOptions(identifier: self::UID_THREE));

        $this->assertSame([], $this->errors($merged));
        $this->assertSame(self::UID_THREE, $merged->getMetadata()->getUniqueIdentifier());
        $this->assertSame($fontOne, $merged->getContentManager()->getFontData('EPUB/book-01/fonts/body.otf'));
        $this->assertSame($fontTwo, $merged->getContentManager()->getFontData('EPUB/book-02/fonts/body.otf'));

        $stored = $merged->getContentManager()->getContent('EPUB/book-01/fonts/body.otf');
        $this->assertNotSame($fontOne, $stored);
        $this->assertSame($fontOne, FontObfuscation::apply($stored, FontObfuscation::IDPF, (string) FontObfuscation::key(FontObfuscation::IDPF, self::UID_THREE)));
        $encryption = (string) file_get_contents($merged->getTempDir() . '/META-INF/encryption.xml');
        $this->assertStringContainsString('URI="EPUB/book-01/fonts/body.otf"', $encryption);
        $this->assertStringContainsString('URI="EPUB/book-02/fonts/body.otf"', $encryption);
    }

    public function testTheSameFontIsKeptOnceWhateverItsObfuscation(): void
    {
        $font = random_bytes(3000);
        $one = $this->bookWithFont(self::UID_ONE, 'One', 'EPUB/fonts/body.otf', $font);
        $two = $this->bookWithFont(self::UID_TWO, 'Two', 'EPUB/fonts/body.otf', $font);

        $merged = $this->merge([$one, $two]);

        $this->assertNull($merged->getManifest()->findByPath('EPUB/book-02/fonts/body.otf'));
        $this->assertSame($font, $merged->getContentManager()->getFontData('EPUB/book-01/fonts/body.otf'));
        $this->assertSame([], $this->errors($merged));
    }

    public function testPlainFontsStayPlain(): void
    {
        $font = random_bytes(2000);
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, '<item id="font" href="fonts/a.otf" media-type="font/otf"/>', ['EPUB/fonts/a.otf' => $font]));

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two', 1))]);

        $this->assertSame($font, $merged->getContentManager()->getContent('EPUB/book-01/fonts/a.otf'));
        $this->assertFileDoesNotExist($merged->getTempDir() . '/META-INF/encryption.xml');
    }

    private function bookWithFont(string $uid, string $title, string $path, string $font): EpubFile
    {
        $book = $this->open(MergeBook::builder($uid, $title, 1));
        $book->getContentManager()->addFont($path, $font);
        $saved = $this->tmpDir . DIRECTORY_SEPARATOR . 'font-' . bin2hex(random_bytes(4)) . '.epub';
        $book->save($saved);

        return $this->opened[] = EpubFile::open($saved);
    }

    public function testRefusesDrmProtectedBooks(): void
    {
        $drm = MergeBook::builder(self::UID_ONE, 'One', 1, '', ['META-INF/rights.xml' => '<rights/>']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('DRM');
        (new Merger())->merge([$this->open($drm), $this->open(MergeBook::builder(self::UID_TWO, 'Two'))], new MergeOptions(), $this->tmpDir . '/out.epub');
    }

    public function testRefusesTooFewBooks(): void
    {
        $this->expectException(Exception::class);
        (new Merger())->merge([$this->open(MergeBook::builder(self::UID_ONE, 'One'))], new MergeOptions(), $this->tmpDir . '/out.epub');
    }

    public function testBookLimitBoundary(): void
    {
        $books = [
            $this->open(MergeBook::builder(self::UID_ONE, 'One')),
            $this->open(MergeBook::builder(self::UID_TWO, 'Two')),
            $this->open(MergeBook::builder(self::UID_THREE, 'Three')),
        ];

        $merged = $this->merge($books, new MergeOptions(maxBooks: 3));
        $this->assertCount(6, $merged->getSpine()->get());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Too many books');
        $this->merge($books, new MergeOptions(maxBooks: 2));
    }

    public function testSizeLimitBoundary(): void
    {
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One'));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two'));
        $total = $this->directoryBytes($one) + $this->directoryBytes($two);

        $merged = $this->merge([$one, $two], new MergeOptions(maxTotalBytes: $total));
        $this->assertCount(4, $merged->getSpine()->get());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('too large');
        $this->merge([$one, $two], new MergeOptions(maxTotalBytes: $total - 1));
    }

    public function testLimitsMustBeSane(): void
    {
        $this->expectException(Exception::class);
        new MergeOptions(maxBooks: 1);
    }

    public function testOneSectionPerBookIsTheDefault(): void
    {
        $merged = $this->merge([$this->open(MergeBook::builder(self::UID_ONE, 'One')), $this->open(MergeBook::builder(self::UID_TWO, 'Two', 3))]);

        $entries = $merged->getTableOfContents()->getEntries();
        $this->assertSame(['One', 'Two'], array_map(static fn (TocEntry $entry): string => $entry->title, $entries));
        $this->assertSame('EPUB/book-01/text/chapter-1.xhtml', $entries[0]->path);
        $this->assertCount(2, $entries[0]->children);
        $this->assertCount(3, $entries[1]->children);
        $this->assertSame('EPUB/book-02/text/chapter-3.xhtml', $entries[1]->children[2]->path);

        $this->assertSame('ncx', $merged->getSpine()->getToc());
        // The NCX carries the same tree.
        $ncx = (string) file_get_contents($merged->getTempDir() . '/EPUB/toc.ncx');
        $this->assertSame(7, substr_count($ncx, '<navPoint'));
        $this->assertStringContainsString('<text>One</text>', $ncx);
        $this->assertStringContainsString('book-02/text/chapter-3.xhtml', $ncx);
    }

    public function testFlatTableOfContents(): void
    {
        $merged = $this->merge(
            [$this->open(MergeBook::builder(self::UID_ONE, 'One')), $this->open(MergeBook::builder(self::UID_TWO, 'Two', 3))],
            new MergeOptions(oneSectionPerBook: false)
        );

        $entries = $merged->getTableOfContents()->getEntries();
        $this->assertCount(5, $entries);
        $this->assertSame('Two chapter 1', $entries[2]->title);
        $this->assertSame([], $entries[0]->children);
        $this->assertSame([], $this->errors($merged));
    }

    public function testMetadataComesFromTheOptionsOrTheFirstBook(): void
    {
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One'));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two'));

        $defaults = $this->merge([$one, $two])->getMetadata();
        $this->assertSame('One', $defaults->getTitle());
        $this->assertSame(['Author of One'], $defaults->getAuthors());
        $this->assertSame('en', $defaults->getLanguage());
        $this->assertMatchesRegularExpression('/^urn:uuid:[0-9a-f-]{36}$/', (string) $defaults->getUniqueIdentifier());
        $this->assertNotContains($defaults->getUniqueIdentifier(), [self::UID_ONE, self::UID_TWO]);

        $options = $this->merge([$one, $two], new MergeOptions(title: 'Omnibus', authors: ['A', 'B'], language: 'fr', identifier: 'urn:isbn:9780000000002'))->getMetadata();
        $this->assertSame('Omnibus', $options->getTitle());
        $this->assertSame(['A', 'B'], $options->getAuthors());
        $this->assertSame('fr', $options->getLanguage());
        $this->assertSame('urn:isbn:9780000000002', $options->getUniqueIdentifier());
    }

    public function testAccessibilityMetadataIsCombined(): void
    {
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One'));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two'));
        $two->getMetadata()->setAccessibilityHazards(['flashing']);
        $two->getMetadata()->setAccessibilityFeatures(['tableOfContents', 'alternativeText']);

        $metadata = $this->merge([$one, $two])->getMetadata();

        $this->assertSame(['textual'], $metadata->getAccessModes());
        $this->assertSame(['tableOfContents', 'alternativeText'], $metadata->getAccessibilityFeatures());
        $this->assertSame(['flashing'], $metadata->getAccessibilityHazards());
        $this->assertSame('Plain text with a table of contents.', $metadata->getAccessibilitySummary());
    }

    public function testModifiedDateComesFromTheClock(): void
    {
        $clock = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2030-05-06 07:08:09', new \DateTimeZone('Europe/Bucharest'));

        $merged = $this->merge(
            [$this->open(MergeBook::builder(self::UID_ONE, 'One')), $this->open(MergeBook::builder(self::UID_TWO, 'Two'))],
            new MergeOptions(clock: $clock)
        );

        $this->assertSame('2030-05-06T04:08:09Z', $merged->getMetadata()->getModifiedDate());
        $this->assertSame([], $this->errors($merged));
    }

    public function testCoverComesFromTheFirstBookOrTheOptions(): void
    {
        $png = (string) base64_decode(EpubBuilder::PNG, true);
        $items = '<item id="cover" href="images/cover.png" media-type="image/png" properties="cover-image"/>';
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, $items, ['EPUB/images/cover.png' => $png], metadata: '<meta name="cover" content="cover"/>'));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two', 1, $items, ['EPUB/images/cover.png' => $png . 'x'], metadata: '<meta name="cover" content="cover"/>'));

        $merged = $this->merge([$one, $two]);
        $cover = $merged->getCoverImage();
        $this->assertSame('EPUB/book-01/images/cover.png', $cover?->path);
        $this->assertCount(1, array_filter($merged->getManifest()->getItems(), static fn ($item): bool => str_contains($item->properties, 'cover-image')));

        $custom = $this->merge([$one, $two], new MergeOptions(coverImage: $png, coverMediaType: 'image/png'));
        $this->assertSame('EPUB/images/cover.png', $custom->getCoverImage()?->path);
        $this->assertSame([], $this->errors($custom));
    }

    public function testInputBooksAndTheirFilesAreNotChanged(): void
    {
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One'));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two'));
        $hashes = array_map(fn (EpubFile $book): array => $this->hashes((string) $book->getTempDir()), [$one, $two]);
        $this->merge([$one, $two]);

        $this->assertSame($hashes, array_map(fn (EpubFile $book): array => $this->hashes((string) $book->getTempDir()), [$one, $two]));
        $this->assertSame(['chapter1', 'chapter2'], $one->getSpine()->get());
        $this->assertSame('One', $one->getMetadata()->getTitle());
    }

    /**
     * @return array<string, string>
     */
    private function hashes(string $directory): array
    {
        $hashes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $hashes[$file->getPathname()] = (string) md5_file($file->getPathname());
            }
        }

        ksort($hashes);

        return $hashes;
    }

    public function testNonLinearItemsAndSpinePropertiesAreKept(): void
    {
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 2));
        $one->getSpine()->setLinear('chapter2', false);
        $one->getSpine()->setPageSpread('chapter1', 'left');

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two', 1))]);

        $items = $merged->getSpine()->getItems();
        $this->assertSame(['b01-chapter1', 'b01-chapter2', 'b02-chapter1'], array_map(static fn ($item): string => $item->idref, $items));
        $this->assertSame([true, false, true], array_map(static fn ($item): bool => $item->linear, $items));
        $this->assertSame('left', $merged->getSpine()->getPageSpread('b01-chapter1'));
    }

    public function testMixedLayoutsGetPerItemOverrides(): void
    {
        $fixed = $this->open(MergeBook::builder(self::UID_ONE, 'Fixed', 2, metadata: '<meta property="rendition:layout">pre-paginated</meta>'));
        $flowing = $this->open(MergeBook::builder(self::UID_TWO, 'Flowing', 1));

        $merged = $this->merge([$fixed, $flowing]);

        $this->assertNull($merged->getMetadata()->getRenditionLayout());
        $this->assertSame('pre-paginated', $merged->getSpine()->getItemRendition('b01-chapter1', 'layout'));
        $this->assertSame('pre-paginated', $merged->getSpine()->getItemRendition('b01-chapter2', 'layout'));
        $this->assertNull($merged->getSpine()->getItemRendition('b02-chapter1', 'layout'));
    }

    public function testUniformFixedLayoutIsSetOnThePackage(): void
    {
        $fixed = '<meta property="rendition:layout">pre-paginated</meta>';

        $merged = $this->merge([
            $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, metadata: $fixed)),
            $this->open(MergeBook::builder(self::UID_TWO, 'Two', 1, metadata: $fixed)),
        ]);

        $this->assertSame('pre-paginated', $merged->getMetadata()->getRenditionLayout());
        $this->assertNull($merged->getSpine()->getItemRendition('b01-chapter1', 'layout'));
    }

    public function testSourceNavigationAndNcxAreReplaced(): void
    {
        $merged = $this->merge([$this->fixture('valid_1.epub'), $this->fixture('valid_2.epub')]);

        $navigations = array_filter($merged->getManifest()->getItems(), static fn ($item): bool => in_array('nav', explode(' ', $item->properties), true));
        $this->assertCount(1, $navigations);
        $ncx = array_filter($merged->getManifest()->getItems(), static fn ($item): bool => $item->mediaType === 'application/x-dtbncx+xml');
        $this->assertCount(1, $ncx);
        $this->assertSame([], $this->errors($merged));
    }

    public function testLegacyDoctypesAndEntitiesBecomeHtml5(): void
    {
        $merged = $this->merge([$this->fixture('valid_1.epub'), $this->fixture('valid.epub')]);

        $content = $merged->getContentManager()->getContent('EPUB/book-01/001.html');
        $this->assertStringNotContainsString('XHTML 1.1', $content);
        $this->assertStringNotContainsString('&nbsp;', $content);
        $this->assertSame([], $this->errors($merged));
    }

    public function testMediaOverlaysFollowTheirDocuments(): void
    {
        $smil = '<?xml version="1.0" encoding="UTF-8"?><smil xmlns="http://www.w3.org/ns/SMIL" version="3.0"><body><par id="p1"><text src="text/chapter-1.xhtml"/></par></body></smil>';
        $items = '<item id="overlay" href="text/chapter-1.smil" media-type="application/smil+xml"/>';
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, $items, ['EPUB/text/chapter-1.smil' => $smil], metadata: '<meta property="media:duration" refines="#overlay">0:00:10</meta>'));
        $one->getManifest()->setMediaOverlay('chapter1', 'overlay');

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two', 1))]);

        $this->assertSame('b01-overlay', $merged->getManifest()->getMediaOverlay('b01-chapter1'));
        $this->assertSame('0:00:10', $merged->getMetadata()->getMediaDurationOf('b01-overlay'));
    }

    public function testLinksToTheDroppedNavigationDocumentBecomePlainText(): void
    {
        $bodies = [1 => '<p>Back to <a href="../nav.xhtml#toc"><em>the contents</em></a> or <a href="chapter-2.xhtml">on</a>.</p>'];
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 2, chapterBodies: $bodies));

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two'))]);

        $content = $merged->getContentManager()->getContent('EPUB/book-01/text/chapter-1.xhtml');
        $this->assertStringNotContainsString('nav.xhtml', $content);
        $this->assertStringContainsString('<em>the contents</em>', $content);
        $this->assertStringContainsString('<a href="chapter-2.xhtml">on</a>', $content);
        $this->assertSame([], $this->errors($merged));
    }

    public function testRemoteItemsAndFallbacksAreCopied(): void
    {
        $items = '<item id="audio" href="https://example.com/a.mp3" media-type="audio/mpeg" properties="remote-resources"/>'
            . '<item id="odd" href="odd.xyz" media-type="application/x-odd" fallback="chapter1"/>';
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, $items, ['EPUB/odd.xyz' => 'odd']));

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two'))]);

        $audio = $merged->getManifest()->get('b01-audio');
        $this->assertSame('https://example.com/a.mp3', $audio?->href);
        $this->assertSame('', $audio->path);
        $this->assertSame('audio/mpeg', $audio->mediaType);
        $this->assertSame('b01-chapter1', $merged->getManifest()->getFallback('b01-odd'));
    }

    public function testTheTotalDurationOfMediaOverlaysIsTheSumOfTheOverlays(): void
    {
        $smil = '<?xml version="1.0" encoding="UTF-8"?><smil xmlns="http://www.w3.org/ns/SMIL" version="3.0"><body><par id="p1"><text src="text/chapter-1.xhtml"/></par></body></smil>';
        $items = '<item id="overlay" href="text/chapter-1.smil" media-type="application/smil+xml"/>';
        $books = [];
        foreach ([[self::UID_ONE, '0:00:10'], [self::UID_TWO, '5.5s']] as [$uid, $duration]) {
            $book = $this->open(MergeBook::builder($uid, 'Book ' . $duration, 1, $items, ['EPUB/text/chapter-1.smil' => $smil], metadata: '<meta property="media:duration" refines="#overlay">' . $duration . '</meta>'));
            $book->getManifest()->setMediaOverlay('chapter1', 'overlay');
            $books[] = $book;
        }

        $merged = $this->merge($books);

        $this->assertSame('15.500s', $merged->getMetadata()->getMediaDuration());
        $this->assertSame('5.5s', $merged->getMetadata()->getMediaDurationOf('b02-overlay'));
    }

    public function testFileLimitBoundary(): void
    {
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One'));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two'));
        $files = 0;
        foreach ([$one, $two] as $book) {
            $files += iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) $book->getTempDir(), \FilesystemIterator::SKIP_DOTS)));
        }

        $this->assertCount(4, $this->merge([$one, $two], new MergeOptions(maxTotalFiles: $files))->getSpine()->get());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('too many files');
        $this->merge([$one, $two], new MergeOptions(maxTotalFiles: $files - 1));
    }

    public function testMergingThousandsOfFilesStaysFast(): void
    {
        $books = [];
        foreach ([self::UID_ONE, self::UID_TWO] as $uid) {
            $items = '';
            $files = [];
            for ($number = 0; $number < 700; ++$number) {
                $items .= "<item id=\"img{$number}\" href=\"img/p{$number}.png\" media-type=\"image/png\"/>";
                $files["EPUB/img/p{$number}.png"] = $uid . $number;
            }

            $books[] = $this->open(MergeBook::builder($uid, 'Big ' . substr($uid, 9, 1), 700, $items, $files));
        }

        $start = microtime(true);
        $merged = $this->merge($books);
        $seconds = microtime(true) - $start;

        $this->assertCount(1400, $merged->getSpine()->get());
        $this->assertLessThan(8.0, $seconds, 'Merging 2800 files took too long: something is quadratic again.');
    }

    public function testMergedNcxFollowsThePlayOrderRule(): void
    {
        $merged = $this->merge([$this->open(MergeBook::builder(self::UID_ONE, 'One')), $this->open(MergeBook::builder(self::UID_TWO, 'Two', 3))]);

        $points = NcxPlayOrderTest::navPoints((string) file_get_contents($merged->getTempDir() . '/EPUB/toc.ncx'));

        $this->assertCount(7, $points);
        // The parent of each book points at its first chapter and shares its playOrder.
        $this->assertSame($points[0][1], $points[1][1]);
        $this->assertSame([1, 1, 2, 3, 3, 4, 5], array_column($points, 1));
        NcxPlayOrderTest::assertPlayOrderRule($points);
    }

    public function testBooksWithFilesOutsideThePackageDirectoryKeepTheirLayout(): void
    {
        $items = '<item id="shared" href="../shared/pic.png" media-type="image/png"/>';
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, $items, ['shared/pic.png' => 'shared-picture']));

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two'))]);

        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $merged->getManifest()->findByPath('EPUB/book-01/EPUB/text/chapter-1.xhtml'));
        $this->assertSame('shared-picture', $merged->getContentManager()->getContent('EPUB/book-01/shared/pic.png'));
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $merged->getManifest()->findByPath('EPUB/book-02/text/chapter-1.xhtml'));
    }

    public function testMissingFilesAndUnreadableEncryptionInfoDoNotStopTheMerge(): void
    {
        $items = '<item id="ghost" href="images/ghost.png" media-type="image/png"/>';
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, $items, ['META-INF/encryption.xml' => 'this is not xml']));

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two'))]);

        $this->assertNull($merged->getManifest()->get('b01-ghost'));
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $merged->getManifest()->get('b01-chapter1'));
    }

    public function testAFontThatCannotBeDeobfuscatedIsCopiedAsItIs(): void
    {
        $font = random_bytes(2000);
        $encryption = '<?xml version="1.0"?><encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#">'
            . '<enc:EncryptedData><enc:EncryptionMethod Algorithm="' . FontObfuscation::ADOBE . '"/><enc:CipherData><enc:CipherReference URI="EPUB/fonts/a.otf"/></enc:CipherData></enc:EncryptedData></encryption>';
        $items = '<item id="font" href="fonts/a.otf" media-type="font/otf"/>';
        // Adobe's algorithm needs a urn:uuid identifier, and this book has none.
        $one = $this->open(MergeBook::builder('urn:isbn:9780000000002', 'One', 1, $items, ['EPUB/fonts/a.otf' => $font, 'META-INF/encryption.xml' => $encryption]));

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two'))]);

        $this->assertSame($font, $merged->getContentManager()->getContent('EPUB/book-01/fonts/a.otf'));
    }

    public function testLegacyDoctypeKeepsXmlEntities(): void
    {
        $chapter = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">'
            . '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>T</title></head><body><p>a &amp; b &lt; c&nbsp;d</p></body></html>';
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, files: ['EPUB/text/chapter-1.xhtml' => $chapter]));

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two'))]);

        $content = $merged->getContentManager()->getContent('EPUB/book-01/text/chapter-1.xhtml');
        $this->assertStringContainsString('<!DOCTYPE html>', $content);
        $this->assertStringContainsString('a &amp; b &lt; c&#160;d', $content);
    }

    public function testStylesheetsFollowDeduplicatedImages(): void
    {
        $css = 'p { background: url(bg.png) }';
        $items = '<item id="bg" href="css/bg.png" media-type="image/png"/>';
        $files = ['EPUB/css/bg.png' => 'same-background'];
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, $items, $files, $css));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two', 1, $items, $files, $css));

        $merged = $this->merge([$one, $two]);

        $this->assertNull($merged->getManifest()->findByPath('EPUB/book-02/css/bg.png'));
        $this->assertStringContainsString('url(../../book-01/css/bg.png)', $merged->getContentManager()->getContent('EPUB/book-02/css/style.css'));
        $this->assertStringContainsString('url(bg.png)', $merged->getContentManager()->getContent('EPUB/book-01/css/style.css'));
    }

    public function testIdsThatCollideAfterCleaningGetANumber(): void
    {
        $items = '<item id="a:b" href="images/one.png" media-type="image/png"/><item id="a_b" href="images/two.png" media-type="image/png"/>';
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, $items, ['EPUB/images/one.png' => 'one', 'EPUB/images/two.png' => 'two']));

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two'))]);

        $this->assertSame('EPUB/book-01/images/one.png', $merged->getManifest()->get('b01-a_b')?->path);
        $this->assertSame('EPUB/book-01/images/two.png', $merged->getManifest()->get('b01-a_b-2')?->path);
    }

    public function testLanguagesAndPublisherAreCarriedOver(): void
    {
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, metadata: '<dc:publisher>Press</dc:publisher>'));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two', 1, metadata: '<dc:language>fr</dc:language>'));
        $three = $this->open(MergeBook::builder(self::UID_THREE, 'Three', 1, metadata: '<dc:language>not a language tag</dc:language>'));

        $metadata = $this->merge([$one, $two])->getMetadata();
        $this->assertSame(['en', 'fr'], $metadata->getLanguages());
        $this->assertSame('Press', $metadata->getPublisher());

        // A language that is not a tag is left out; the main one stays.
        $this->assertSame(['en'], $this->merge([$one, $three])->getMetadata()->getLanguages());
    }

    public function testSpineEntriesWithoutItemsAreSkippedAndTheDirectionIsKept(): void
    {
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, spineAttributes: ' page-progression-direction="rtl"', spineItems: '<itemref idref="ghost"/><itemref idref="chapter1"/>'));

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two', 1))]);

        $this->assertSame(['b01-chapter1', 'b02-chapter1'], $merged->getSpine()->get());
        $this->assertSame('rtl', $merged->getSpine()->getPageProgressionDirection());
    }

    public function testDurationUnitsAreAddedUp(): void
    {
        $smil = '<?xml version="1.0" encoding="UTF-8"?><smil xmlns="http://www.w3.org/ns/SMIL" version="3.0"><body><par id="p1"><text src="text/chapter-1.xhtml"/></par></body></smil>';
        $items = '<item id="overlay" href="text/chapter-1.smil" media-type="application/smil+xml"/>';
        $books = [];
        foreach ([[self::UID_ONE, '1h'], [self::UID_TWO, '2min'], [self::UID_THREE, '500ms']] as [$uid, $duration]) {
            $book = $this->open(MergeBook::builder($uid, 'Book ' . $duration, 1, $items, ['EPUB/text/chapter-1.smil' => $smil], metadata: '<meta property="media:duration" refines="#overlay">' . $duration . '</meta>'));
            $book->getManifest()->setMediaOverlay('chapter1', 'overlay');
            $books[] = $book;
        }

        $this->assertSame('3720.500s', $this->merge($books)->getMetadata()->getMediaDuration());
    }

    public function testNoTotalDurationWhenAnOverlayDurationIsNotAClockValue(): void
    {
        $smil = '<?xml version="1.0" encoding="UTF-8"?><smil xmlns="http://www.w3.org/ns/SMIL" version="3.0"><body><par id="p1"><text src="text/chapter-1.xhtml"/></par></body></smil>';
        $items = '<item id="overlay" href="text/chapter-1.smil" media-type="application/smil+xml"/>';
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, $items, ['EPUB/text/chapter-1.smil' => $smil], metadata: '<meta property="media:duration" refines="#overlay">a long time</meta>'));
        $one->getManifest()->setMediaOverlay('chapter1', 'overlay');

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two'))]);

        $this->assertSame('b01-overlay', $merged->getManifest()->getMediaOverlay('b01-chapter1'));
        $this->assertNull($merged->getMetadata()->getMediaDuration());
        $this->assertNull($merged->getMetadata()->getMediaDurationOf('b01-overlay'));
    }

    public function testBooksWithAnUnreadableNavigationStillMerge(): void
    {
        $broken = ['EPUB/nav.xhtml' => '<html><body><nav epub:type="toc"><ol><li>'];
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 1, files: $broken));
        $two = $this->open(MergeBook::builder(self::UID_TWO, 'Two', 1, files: $broken));

        $sections = $this->merge([$one, $two]);
        $this->assertSame(['One', 'Two'], array_map(static fn (TocEntry $entry): string => $entry->title, $sections->getTableOfContents()->getEntries()));

        // Without sections, a book without entries still gets one.
        $flat = $this->merge([$one, $two], new MergeOptions(oneSectionPerBook: false));
        $this->assertSame(['One', 'Two'], array_map(static fn (TocEntry $entry): string => $entry->title, $flat->getTableOfContents()->getEntries()));
        $this->assertSame('EPUB/book-01/text/chapter-1.xhtml', $flat->getTableOfContents()->getEntries()[0]->path);
    }

    public function testTheCoverLandmarkOfTheFirstBookIsKept(): void
    {
        $nav = EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><h1>Contents</h1><ol><li><a href="text/chapter-1.xhtml">One</a></li>'
            . '<li><a href="text/missing.xhtml">Lost part</a><ol><li><a href="text/chapter-2.xhtml">Two</a></li></ol></li></ol></nav>'
            . '<nav epub:type="landmarks"><ol><li><a epub:type="cover" href="text/chapter-1.xhtml">Cover</a></li><li><a epub:type="bodymatter" href="text/chapter-2.xhtml">Start</a></li></ol></nav>');
        $one = $this->open(MergeBook::builder(self::UID_ONE, 'One', 2, files: ['EPUB/nav.xhtml' => $nav]));

        $merged = $this->merge([$one, $this->open(MergeBook::builder(self::UID_TWO, 'Two'))]);

        $landmarks = $merged->getTableOfContents()->getLandmarks();
        $this->assertCount(1, $landmarks);
        $this->assertSame('cover', $landmarks[0]->type);
        $this->assertSame('EPUB/book-01/text/chapter-1.xhtml', $landmarks[0]->path);

        // The entry whose own page is not in the book stays as a heading above its child.
        $children = $merged->getTableOfContents()->getEntries()[0]->children;
        $this->assertSame('Lost part', $children[1]->title);
        $this->assertSame('', $children[1]->path);
        $this->assertSame('EPUB/book-01/text/chapter-2.xhtml', $children[1]->children[0]->path);
    }
}
