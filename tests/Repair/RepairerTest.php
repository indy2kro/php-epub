<?php

declare(strict_types=1);

namespace PhpEpub\Test\Repair;

use DateTimeImmutable;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\ManifestItem;
use PhpEpub\Repair\AppliedFix;
use PhpEpub\Repair\RepairFix;
use PhpEpub\Repair\RepairOptions;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ValidationIssue;
use PHPUnit\Framework\TestCase;

final class RepairerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'repair';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testAValidBookNeedsNoRepair(): void
    {
        foreach ([EpubBuilder::epub3(), EpubBuilder::epub2()] as $book) {
            $epub = $this->open($book);
            $this->assertSame([], $epub->repair());
        }
    }

    public function testSetsAMissingModifiedDateFromTheClock(): void
    {
        $book = $this->withOpf(EpubBuilder::epub3(), static fn (string $opf): string => (string) preg_replace('#<meta property="dcterms:modified">[^<]*</meta>#', '', $opf));
        $epub = $this->open($book);
        $this->assertContains('METADATA_MODIFIED_MISSING', $this->codes($epub->validate()));

        $fixes = $epub->repair(new RepairOptions(now: new DateTimeImmutable('2026-10-09T12:30:00+02:00')));

        $this->assertSame(['METADATA_MODIFIED_MISSING'], $this->fixCodes($fixes));
        $this->assertSame('2026-10-09T10:30:00Z', $epub->getMetadata()->getProperty('dcterms:modified'));
        $this->assertNotContains('METADATA_MODIFIED_MISSING', $this->codes($epub->validate()));
    }

    public function testSetsTheDefaultLanguageWhenThereIsNone(): void
    {
        $book = $this->withOpf(EpubBuilder::epub3(), static fn (string $opf): string => str_replace('<dc:language>en</dc:language>', '', $opf));

        $epub = $this->open($book);
        $fixes = $epub->repair();
        $this->assertSame(['METADATA_LANGUAGE_MISSING'], $this->fixCodes($fixes));
        $this->assertSame('en', $epub->getMetadata()->getLanguage());

        $epub = $this->open($book);
        $epub->repair((new RepairOptions())->withDefaultLanguage('fr-CA'));
        $this->assertSame('fr-CA', $epub->getMetadata()->getLanguage());
        $this->assertNotContains('METADATA_LANGUAGE_MISSING', $this->codes($epub->validate()));
    }

    public function testRejectsADefaultLanguageThatIsNotATag(): void
    {
        $this->expectException(Exception::class);

        new RepairOptions(defaultLanguage: 'not a tag');
    }

    public function testCreatesAnIdentifierWhenThePackageHasNone(): void
    {
        $book = $this->withOpf(EpubBuilder::epub3(), static fn (string $opf): string => (string) preg_replace('#<dc:identifier[^>]*>[^<]*</dc:identifier>#', '', $opf));
        $epub = $this->open($book);
        $this->assertContains('METADATA_IDENTIFIER_MISSING', $this->codes($epub->validate()));

        $fixes = $epub->repair();

        $this->assertSame(['METADATA_IDENTIFIER_MISSING'], $this->fixCodes($fixes));
        $this->assertStringStartsWith('urn:uuid:', (string) $epub->getMetadata()->getUniqueIdentifier());
        $this->assertNotContains('METADATA_IDENTIFIER_MISSING', $this->codes($epub->validate()));
        $this->assertNotContains('METADATA_UNIQUE_IDENTIFIER', $this->codes($epub->validate()));
    }

    public function testPointsTheUniqueIdentifierAtAnExistingIdentifier(): void
    {
        $book = $this->withOpf(EpubBuilder::epub3(), static fn (string $opf): string => str_replace('unique-identifier="uid"', 'unique-identifier="gone"', $opf));
        $epub = $this->open($book);
        $this->assertContains('METADATA_UNIQUE_IDENTIFIER', $this->codes($epub->validate()));

        $fixes = $epub->repair();

        $this->assertSame(['METADATA_UNIQUE_IDENTIFIER'], $this->fixCodes($fixes));
        $this->assertSame('urn:uuid:3f1e2a4c-5b6d-4e7f-8a9b-0c1d2e3f4a5b', $epub->getMetadata()->getUniqueIdentifier());
        $this->assertSame([], $this->codes($epub->validate()));
    }

    public function testFillsAnEmptyIdentifier(): void
    {
        $book = $this->withOpf(EpubBuilder::epub3(), static fn (string $opf): string => (string) preg_replace('#(<dc:identifier id="uid">)[^<]*#', '$1', $opf));
        $epub = $this->open($book);

        $this->assertSame(['METADATA_IDENTIFIER_MISSING'], $this->fixCodes($epub->repair()));
        $this->assertStringStartsWith('urn:uuid:', (string) $epub->getMetadata()->getUniqueIdentifier());
    }

    public function testCreatesTheNavigationDocumentOfAnEpub3Book(): void
    {
        $book = $this->withOpf(EpubBuilder::epub3(), static fn (string $opf): string => (string) preg_replace('#<item id="nav"[^>]*/>#', '', $opf))
            ->withoutFile('EPUB/nav.xhtml');
        $epub = $this->open($book);
        $this->assertContains('NAV_MISSING', $this->codes($epub->validate()));

        $fixes = $epub->repair();

        $this->assertSame(['NAV_MISSING'], $this->fixCodes($fixes));
        $this->assertSame([], $this->codes($epub->validate()));
        $this->assertSame('Chapter', $epub->getTableOfContents()->getEntries()[0]->title);
    }

    public function testCreatesTheNcxOfAnEpub2Book(): void
    {
        $book = $this->withOpf(EpubBuilder::epub2(), static fn (string $opf): string => (string) preg_replace('#<item id="ncx"[^>]*/>#', '', str_replace('<spine toc="ncx">', '<spine>', $opf)))
            ->withoutFile('OEBPS/toc.ncx');
        $epub = $this->open($book);
        $this->assertContains('NCX_MISSING', $this->codes($epub->validate()));

        $fixes = $epub->repair();

        $this->assertContains('NCX_MISSING', $this->fixCodes($fixes));
        $this->assertSame([], $this->codes($epub->validate()));
        $this->assertSame(['Chapter'], array_map(static fn ($entry): string => $entry->title, $epub->getTableOfContents()->getEntries()));

        $saved = $this->tmpDir . '/saved.epub';
        $epub->save($saved);
        $reopened = EpubFile::open($saved);
        $this->assertSame([], $this->codes($reopened->validate()));
        $this->assertStringContainsString('dtb:uid', (string) file_get_contents($reopened->getTempDir() . '/OEBPS/toc.ncx'));
    }

    public function testFillsAnEmptyNavigationDocument(): void
    {
        $book = EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><h1>Contents</h1><ol></ol></nav>'));
        $epub = $this->open($book);
        $this->assertContains('NAV_EMPTY', $this->codes($epub->validate()));

        $this->assertSame(['NAV_EMPTY'], $this->fixCodes($epub->repair()));
        $this->assertNotContains('NAV_EMPTY', $this->codes($epub->validate()));
    }

    public function testFillsAnEmptyNcxFromTheReadingOrderWhenThereAreNoHeadings(): void
    {
        $emptyNcx = (string) preg_replace('#<navPoint.*</navPoint>#s', '', (string) EpubBuilder::epub2()->getFile('OEBPS/toc.ncx'));
        $book = EpubBuilder::epub2()
            ->withFile('OEBPS/toc.ncx', $emptyNcx)
            ->withFile('OEBPS/text/chapter.xhtml', '<?xml version="1.0" encoding="UTF-8"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>C</title></head><body><p>No headings.</p></body></html>');
        $epub = $this->open($book);
        $this->assertContains('NCX_EMPTY', $this->codes($epub->validate()));

        $this->assertSame(['NCX_EMPTY'], $this->fixCodes($epub->repair()));
        $this->assertSame([], $this->codes($epub->validate()));
        $this->assertSame('chapter', $epub->getTableOfContents()->getEntries()[0]->title);
    }

    public function testDropsTableOfContentsLinksToFilesThatAreNotInTheBook(): void
    {
        $nav = EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><ol>'
            . '<li><a href="text/chapter.xhtml">Chapter</a></li>'
            . '<li><a href="text/gone.xhtml">Gone</a></li>'
            . '<li><a href="text/gone.xhtml">Parent</a><ol><li><a href="text/chapter.xhtml#x">Child</a></li></ol></li>'
            . '</ol></nav>');
        $epub = $this->open(EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', $nav));
        $this->assertContains('TOC_LINK_NOT_IN_MANIFEST', $this->codes($epub->validate()));

        $fixes = $epub->repair();

        $this->assertSame(['TOC_LINK_NOT_IN_MANIFEST', 'TOC_LINK_NOT_IN_MANIFEST'], $this->fixCodes($fixes));
        $this->assertSame([], $this->codes($epub->validate()));
        $entries = $epub->getTableOfContents()->getEntries();
        $this->assertSame(['Chapter', 'Parent'], array_map(static fn ($entry): string => $entry->title, $entries));
        $this->assertSame('', $entries[1]->path, 'A parent whose link is gone stays as a heading for its children.');
    }

    public function testRebuildsATableOfContentsWhoseLinksAllLeadNowhere(): void
    {
        $nav = EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><ol><li><a href="text/gone.xhtml">Gone</a></li></ol></nav>');
        $epub = $this->open(EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', $nav));

        $this->assertSame(['TOC_LINK_NOT_IN_MANIFEST', 'NAV_EMPTY'], $this->fixCodes($epub->repair()));
        $this->assertSame([], $this->codes($epub->validate()));
    }

    public function testRemovesManifestItemsWithoutAFileFromTheManifestAndTheSpine(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace(['</manifest>', '</spine>'], ['<item id="gone" href="text/gone.xhtml" media-type="application/xhtml+xml"/></manifest>', '<itemref idref="gone"/></spine>'], $opf)
        );
        $epub = $this->open($book);
        $this->assertContains('MANIFEST_FILE_MISSING', $this->codes($epub->validate()));

        $fixes = $epub->repair();

        $this->assertSame(['MANIFEST_FILE_MISSING'], $this->fixCodes($fixes));
        $this->assertSame('EPUB/text/gone.xhtml', $fixes[0]->location);
        $this->assertSame(['chapter'], $epub->getSpine()->get());
        $this->assertNotInstanceOf(ManifestItem::class, $epub->getManifest()->get('gone'));
        $this->assertSame([], $this->codes($epub->validate()));
    }

    public function testRemovesSpineReferencesToUnknownItemsAndKeepsTheFirstOfADuplicate(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace('<itemref idref="chapter"/>', '<itemref idref="nope"/><itemref idref="chapter"/><itemref idref="chapter" linear="no"/><itemref idref="nope"/>', $opf)
        );
        $epub = $this->open($book);
        $codes = $this->codes($epub->validate());
        $this->assertContains('SPINE_UNKNOWN_IDREF', $codes);
        $this->assertContains('SPINE_DUPLICATE_IDREF', $codes);

        $fixes = $epub->repair();

        $this->assertEqualsCanonicalizing(['SPINE_UNKNOWN_IDREF', 'SPINE_UNKNOWN_IDREF', 'SPINE_DUPLICATE_IDREF'], $this->fixCodes($fixes));
        $items = $epub->getSpine()->getItems();
        $this->assertCount(1, $items);
        $this->assertTrue($items[0]->linear, 'The first reference stays.');
        $this->assertSame([], $this->codes($epub->validate()));
    }

    public function testAddsUnlistedFilesToTheManifestWithoutTheSpine(): void
    {
        $book = EpubBuilder::epub3()
            ->withFile('EPUB/text/extra.xhtml', EpubBuilder::xhtml('Extra', '<p>Extra</p>'))
            ->withFile('EPUB/images/pic.png', (string) base64_decode(EpubBuilder::PNG, true))
            ->withFile('EPUB/images/photo.dat', (string) base64_decode(EpubBuilder::JPEG, true))
            ->withFile('EPUB/.DS_Store', 'junk')
            ->withFile('EPUB/Thumbs.db', 'junk');
        $epub = $this->open($book);
        $this->assertContains('FILE_NOT_IN_MANIFEST', $this->codes($epub->validate()));

        $fixes = $epub->repair();

        $this->assertSame(['FILE_NOT_IN_MANIFEST', 'FILE_NOT_IN_MANIFEST', 'FILE_NOT_IN_MANIFEST'], $this->fixCodes($fixes));
        $manifest = $epub->getManifest();
        $this->assertSame('application/xhtml+xml', $manifest->findByPath('EPUB/text/extra.xhtml')?->mediaType);
        $this->assertSame('image/png', $manifest->findByPath('EPUB/images/pic.png')?->mediaType);
        $this->assertSame('image/jpeg', $manifest->findByPath('EPUB/images/photo.dat')?->mediaType, 'The content decides for an image.');
        $this->assertSame(['chapter'], $epub->getSpine()->get());
        $descriptions = implode(' ', array_map(static fn (AppliedFix $fix): string => $fix->description, $fixes));
        $this->assertStringContainsString('not in the spine', $descriptions);

        $remaining = array_map(static fn (ValidationIssue $issue): ?string => $issue->location, $epub->validate());
        $this->assertEqualsCanonicalizing(['EPUB/.DS_Store', 'EPUB/Thumbs.db'], $remaining, 'Junk files stay unlisted.');
    }

    public function testFixesImageMediaTypesFromTheContentAndOthersFromTheExtension(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace('</manifest>', '<item id="pic" href="pic.png" media-type="image/jpeg"/><item id="odd" href="odd.css" media-type="application/xhtml+xml"/></manifest>', $opf)
        )
            ->withFile('EPUB/pic.png', (string) base64_decode(EpubBuilder::PNG, true))
            ->withFile('EPUB/odd.css', 'p { color: red; }');
        $epub = $this->open($book);
        $this->assertContains('MEDIA_TYPE_MISMATCH', $this->codes($epub->validate()));

        $fixes = $epub->repair();

        $this->assertSame(['MEDIA_TYPE_MISMATCH', 'MEDIA_TYPE_MISMATCH'], $this->fixCodes($fixes));
        $this->assertSame('image/png', $epub->getManifest()->get('pic')?->mediaType);
        $this->assertSame('text/css', $epub->getManifest()->get('odd')?->mediaType);
        $this->assertSame([], $this->codes($epub->validate()));
    }

    public function testLeavesAMediaTypeItCannotDecide(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace('</manifest>', '<item id="odd" href="odd.xhtml" media-type="application/xhtml+xml"/></manifest>', $opf)
        )->withFile('EPUB/odd.xhtml', 'not xml at all');
        $epub = $this->open($book);

        $this->assertSame([], $this->fixCodes($epub->repair()));
        $this->assertContains('MEDIA_TYPE_MISMATCH', $this->codes($epub->validate()));
    }

    public function testRenamesRepeatedManifestIds(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace('</manifest>', '<item id="chapter" href="two.xhtml" media-type="application/xhtml+xml"/></manifest>', $opf)
        )->withFile('EPUB/two.xhtml', EpubBuilder::xhtml('Two', '<p>Two</p>'));
        $epub = $this->open($book);
        $this->assertContains('DUPLICATE_ID', $this->codes($epub->validate()));

        $fixes = $epub->repair();

        $this->assertSame(['DUPLICATE_ID'], $this->fixCodes($fixes));
        $this->assertSame('EPUB/text/chapter.xhtml', $epub->getManifest()->get('chapter')?->path, 'The first item keeps the id the spine uses.');
        $this->assertSame('EPUB/two.xhtml', $epub->getManifest()->get('chapter-2')?->path);
        $this->assertSame([], $this->codes($epub->validate()));
    }

    public function testDeclaresACoverNamedLikeOne(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace('</manifest>', '<item id="img" href="images/cover.png" media-type="image/png"/></manifest>', $opf)
        )->withFile('EPUB/images/cover.png', (string) base64_decode(EpubBuilder::PNG, true));
        $epub = $this->open($book);
        $this->assertNotInstanceOf(ManifestItem::class, $epub->getCoverImage());

        $fixes = $epub->repair();

        $this->assertSame(['COVER_NOT_DECLARED'], $this->fixCodes($fixes));
        $cover = $epub->getCoverImage();
        $this->assertInstanceOf(ManifestItem::class, $cover);
        $this->assertSame('img', $cover->id);
        $this->assertSame('cover-image', $epub->getManifest()->get('img')?->properties);
        $this->assertSame('img', $epub->getMetadata()->getMeta('cover'));
        $this->assertSame([], $this->codes($epub->validate()));
    }

    public function testDoesNotGuessACoverFromAnotherName(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace('</manifest>', '<item id="img" href="images/discover.png" media-type="image/png"/></manifest>', $opf)
        )->withFile('EPUB/images/discover.png', (string) base64_decode(EpubBuilder::PNG, true));
        $epub = $this->open($book);

        $this->assertSame([], $this->fixCodes($epub->repair()));
        $this->assertNotInstanceOf(ManifestItem::class, $epub->getCoverImage());
    }

    public function testFlagsACoverThatIsOnlyDeclaredByTheEpub2Meta(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace(['</manifest>', '</metadata>'], ['<item id="img" href="pic.png" media-type="image/png"/></manifest>', '<meta name="cover" content="img"/></metadata>'], $opf)
        )->withFile('EPUB/pic.png', (string) base64_decode(EpubBuilder::PNG, true));
        $epub = $this->open($book);

        $this->assertSame(['COVER_NOT_FLAGGED'], $this->fixCodes($epub->repair()));
        $this->assertSame('cover-image', $epub->getManifest()->get('img')?->properties);
    }

    public function testFlagsACoverThatIsOnlyDeclaredByTheEpub3Property(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace('</manifest>', '<item id="img" href="pic.png" media-type="image/png" properties="cover-image"/></manifest>', $opf)
        )->withFile('EPUB/pic.png', (string) base64_decode(EpubBuilder::PNG, true));
        $epub = $this->open($book);

        $this->assertSame(['COVER_NOT_FLAGGED'], $this->fixCodes($epub->repair()));
        $this->assertSame('img', $epub->getMetadata()->getMeta('cover'));
    }

    public function testNamesTheEpub2CoverMetaWithoutAddingAnEpub3Property(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub2(),
            static fn (string $opf): string => str_replace('</manifest>', '<item id="img" href="cover.png" media-type="image/png"/></manifest>', $opf)
        )->withFile('OEBPS/cover.png', (string) base64_decode(EpubBuilder::PNG, true));
        $epub = $this->open($book);

        $this->assertSame(['COVER_NOT_DECLARED'], $this->fixCodes($epub->repair()));
        $this->assertSame('', $epub->getManifest()->get('img')?->properties);
        $this->assertSame('img', $epub->getMetadata()->getMeta('cover'));
    }

    public function testRemovesTheCoverImagePropertyFromAnItemThatIsNotAnImage(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace('<item id="style" href="css/style.css" media-type="text/css"/>', '<item id="style" href="css/style.css" media-type="text/css" properties="cover-image"/>', $opf)
        );
        $epub = $this->open($book);
        $this->assertContains('COVER_NOT_IMAGE', $this->codes($epub->validate()));

        $this->assertSame(['COVER_NOT_IMAGE'], $this->fixCodes($epub->repair()));
        $this->assertNotContains('COVER_NOT_IMAGE', $this->codes($epub->validate()));
    }

    public function testRecomputesManifestProperties(): void
    {
        $chapter = EpubBuilder::xhtml('Chapter', '<h1>Chapter</h1><script>var a = 1;</script><svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>', '../css/style.css');
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace('<item id="style"', '<item id="x" href="x.xhtml" media-type="application/xhtml+xml" properties="mathml"/><item id="style"', $opf)
        )->withFile('EPUB/text/chapter.xhtml', $chapter)->withFile('EPUB/x.xhtml', EpubBuilder::xhtml('X', '<p>X</p>'));
        $epub = $this->open($book);
        $this->assertContains('MANIFEST_PROPERTY_MISSING', $this->codes($epub->validate()));

        $fixes = $epub->repair();

        $this->assertEqualsCanonicalizing(['MANIFEST_PROPERTY_MISSING', 'MANIFEST_PROPERTY_UNNEEDED'], $this->fixCodes($fixes));
        $this->assertEqualsCanonicalizing(['scripted', 'svg'], explode(' ', (string) $epub->getManifest()->get('chapter')?->properties));
        $this->assertSame('', $epub->getManifest()->get('x')?->properties);
        $this->assertSame([], $this->codes($epub->validate()));
    }

    public function testUpgradesToEpub3OnlyWhenAsked(): void
    {
        $epub = $this->open(EpubBuilder::epub2());
        $this->assertSame([], $this->fixCodes($epub->repair()));
        $this->assertStringStartsWith('2', $epub->getMetadata()->getVersion());

        $fixes = $epub->repair((new RepairOptions())->with(RepairFix::UpgradeToEpub3));

        $this->assertContains('UPGRADED_TO_EPUB3', $this->fixCodes($fixes));
        $this->assertStringStartsWith('3', $epub->getMetadata()->getVersion());
        $codes = $this->codes($epub->validate());
        $this->assertNotContains('NAV_MISSING', $codes);
        $this->assertNotContains('METADATA_MODIFIED_MISSING', $codes);
    }

    public function testEveryRepairCanBeSwitchedOff(): void
    {
        $book = $this->hostileBook();

        $epub = $this->open($book);
        $this->assertSame([], $epub->repair(RepairOptions::only()), 'Nothing selected, nothing changed.');

        $epub = $this->open($book);
        $options = (new RepairOptions())->without(RepairFix::MissingFiles);
        $this->assertNotContains('MANIFEST_FILE_MISSING', $this->fixCodes($epub->repair($options)));
        $this->assertContains('MANIFEST_FILE_MISSING', $this->codes($epub->validate()));

        $epub = $this->open($book);
        $fixes = $epub->repair(RepairOptions::only(RepairFix::Language));
        $this->assertSame(['METADATA_LANGUAGE_MISSING'], $this->fixCodes($fixes));
    }

    public function testASecondRepairChangesNothing(): void
    {
        $epub = $this->open($this->hostileBook());
        $first = $this->fixCodes($epub->repair());
        $this->assertGreaterThan(8, count($first));

        $this->assertSame([], $epub->repair());

        $saved = $this->tmpDir . '/repaired.epub';
        $epub->save($saved);
        $reopened = EpubFile::open($saved);
        $this->assertSame([], $reopened->repair(), 'The saved book needs no repair either.');
    }

    public function testRepairNeverDeletesAFileAndLeavesNoFixedCodeBehind(): void
    {
        $epub = $this->open($this->hostileBook());
        $before = $this->files((string) $epub->getTempDir());

        $fixed = array_unique($this->fixCodes($epub->repair()));
        $after = $this->files((string) $epub->getTempDir());

        $this->assertSame([], array_values(array_diff($before, $after)), 'No file was deleted.');
        $this->assertSame([], array_values(array_intersect($fixed, $this->codes($epub->validate()))), 'No fixed problem is still reported.');
    }

    public function testRepairingAnUnloadedBookIsRefused(): void
    {
        $epub = $this->open(EpubBuilder::epub3());
        $epub->cleanup();

        $this->expectException(Exception::class);
        $epub->repair();
    }

    public function testRepairingAnEncryptedBookKeepsItsEncryptedFiles(): void
    {
        $encryption = '<?xml version="1.0"?><encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#">'
            . '<enc:EncryptedData><enc:EncryptionMethod Algorithm="http://www.w3.org/2001/04/xmlenc#aes128-cbc"/>'
            . '<enc:CipherData><enc:CipherReference URI="EPUB/text/chapter.xhtml"/></enc:CipherData></enc:EncryptedData></encryption>';
        $book = EpubBuilder::epub3()->withFile('META-INF/encryption.xml', $encryption)->withFile('EPUB/text/chapter.xhtml', 'ciphertext');
        $epub = $this->open($book);

        $epub->repair();

        $this->assertSame('ciphertext', (string) file_get_contents($epub->getTempDir() . '/EPUB/text/chapter.xhtml'));
        $this->assertTrue($epub->isDrmProtected());
    }

    public function testThePackageHelpersTheRepairsUse(): void
    {
        $epub = $this->open(EpubBuilder::epub3());

        $this->assertFalse($epub->getMetadata()->ensureUniqueIdentifier('urn:uuid:unused'), 'A usable unique identifier stays.');
        $this->assertSame([], $epub->getManifest()->renameDuplicateIds());

        $epub->getSpine()->setToc('style');
        $epub->getSpine()->setToc(null);
        $this->expectException(Exception::class);
        $epub->getSpine()->removeAt(5);
    }
    public function testEverySingleFixIsCorrectOnABookWithRepeatedManifestIds(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace(
                '</manifest>',
                '<item id="style" href="css/missing.css" media-type="text/css"/><item id="style" href="pic.png" media-type="image/jpeg"/></manifest>',
                $opf
            )
        )->withFile('EPUB/pic.png', (string) base64_decode(EpubBuilder::PNG, true));
        $before = $this->codes($this->open($book)->validate());

        foreach (RepairFix::cases() as $fix) {
            $epub = $this->open($book);
            $first = $epub->repair(RepairOptions::only($fix));
            $codes = $this->codes($epub->validate());

            $this->assertSame([], array_values(array_diff($codes, $before)), "{$fix->value} adds problems.");
            $this->assertSame('EPUB/css/style.css', $epub->getManifest()->get('style')?->path, "{$fix->value} changed the first item.");
            $this->assertSame([], $epub->repair(RepairOptions::only($fix)), "{$fix->value} is not idempotent after " . count($first) . ' fixes.');
        }
    }

    public function testMissingFilesWaitForUniqueIdsWhenIdsAreRepeated(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace('</manifest>', '<item id="style" href="css/missing.css" media-type="text/css"/></manifest>', $opf)
        );
        $epub = $this->open($book);

        $this->assertSame([], $epub->repair(RepairOptions::only(RepairFix::MissingFiles)), 'Removing by a repeated id would remove the wrong item.');
        $this->assertSame('EPUB/css/style.css', $epub->getManifest()->get('style')?->path);

        $fixes = $epub->repair(RepairOptions::only(RepairFix::DuplicateIds, RepairFix::MissingFiles));
        $this->assertSame(['DUPLICATE_ID', 'MANIFEST_FILE_MISSING'], $this->fixCodes($fixes));
        $this->assertSame('style', $epub->getManifest()->findByPath('EPUB/css/style.css')?->id);
    }

    public function testACoverNamedLikeOneMustBeAnImage(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace('</manifest>', '<item id="img" href="cover.jpg" media-type="image/jpeg"/></manifest>', $opf)
        )->withFile('EPUB/cover.jpg', '<html>not an image</html>');
        $epub = $this->open($book);

        $this->assertNotContains('COVER_NOT_DECLARED', $this->fixCodes($epub->repair(RepairOptions::only(RepairFix::Cover))));
        $this->assertNotInstanceOf(ManifestItem::class, $epub->getCoverImage());
    }

    public function testASpineDocumentIsNeverRetypedAsANonContentType(): void
    {
        $book = $this->withOpf(
            EpubBuilder::epub3(),
            static fn (string $opf): string => str_replace(['</manifest>', '</spine>'], ['<item id="front" href="front.txt" media-type="application/xhtml+xml"/></manifest>', '<itemref idref="front"/></spine>'], $opf)
        )->withFile('EPUB/front.txt', 'plain text');
        $epub = $this->open($book);

        $this->assertSame([], $this->fixCodes($epub->repair(RepairOptions::only(RepairFix::MediaTypes))));
        $this->assertSame('application/xhtml+xml', $epub->getManifest()->get('front')?->mediaType);
    }

    public function testBookkeepingFilesOfAppleToolsAreNotListed(): void
    {
        $book = EpubBuilder::epub3()->withFile('iTunesMetadata.plist', 'x')->withFile('ITUNESARTWORK', 'x');
        $epub = $this->open($book);

        $this->assertSame([], $this->fixCodes($epub->repair(RepairOptions::only(RepairFix::UnlistedFiles))));
    }
    /**
     * A book with a dozen problems at once.
     */
    private function hostileBook(): EpubBuilder
    {
        return $this->withOpf(
            EpubBuilder::epub3(),
            static function (string $opf): string {
                $opf = (string) preg_replace('#<meta property="dcterms:modified">[^<]*</meta>#', '', $opf);
                $opf = str_replace('<dc:language>en</dc:language>', '', $opf);
                $opf = str_replace('</manifest>', '<item id="gone" href="gone.xhtml" media-type="application/xhtml+xml"/><item id="img" href="cover.png" media-type="image/jpeg"/><item id="chapter" href="two.xhtml" media-type="application/xhtml+xml"/></manifest>', $opf);

                return str_replace('<itemref idref="chapter"/>', '<itemref idref="chapter"/><itemref idref="gone"/><itemref idref="chapter"/><itemref idref="nope"/>', $opf);
            }
        )
            ->withFile('EPUB/two.xhtml', EpubBuilder::xhtml('Two', '<p>Two</p>'))
            ->withFile('EPUB/cover.png', (string) base64_decode(EpubBuilder::PNG, true))
            ->withFile('EPUB/unlisted.xhtml', EpubBuilder::xhtml('Unlisted', '<script>1</script>'))
            ->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><ol><li><a href="text/lost.xhtml">Lost</a></li></ol></nav>'));
    }

    /**
     * @param \Closure(string): string $edit
     */
    private function withOpf(EpubBuilder $book, \Closure $edit): EpubBuilder
    {
        foreach (['EPUB/package.opf', 'OEBPS/content.opf'] as $path) {
            $opf = $book->getFile($path);
            if ($opf !== null) {
                $book->withFile($path, $edit($opf));
            }
        }

        return $book;
    }

    private function open(EpubBuilder $book): EpubFile
    {
        return EpubFile::open($book->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }

    /**
     * @param list<AppliedFix> $fixes
     *
     * @return list<string>
     */
    private function fixCodes(array $fixes): array
    {
        return array_map(static fn (AppliedFix $fix): string => $fix->code, $fixes);
    }

    /**
     * @param list<ValidationIssue> $issues
     *
     * @return list<string>
     */
    private function codes(array $issues): array
    {
        return array_map(static fn (ValidationIssue $issue): string => $issue->code, $issues);
    }

    /**
     * @return list<string>
     */
    private function files(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        sort($files);

        return $files;
    }
}
