<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Landmark;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\TableOfContents;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\PathResolver;
use PhpEpub\XmlParser;
use PhpEpub\ValidationIssue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpgradeToEpub3Test extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'upgrade';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    #[DataProvider('epub2Fixtures')]
    public function testUpgradedFixtureBooksHaveNoErrors(string $fixture): void
    {
        $epubFile = EpubFile::open(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . $fixture);
        $entries = $epubFile->getTableOfContents()->getEntries();
        $this->assertSame('2.0', $epubFile->getMetadata()->getVersion());

        $this->assertTrue($epubFile->upgradeToEpub3());
        $epubFile->save($this->tmpDir . '/upgraded.epub');

        $reopened = EpubFile::open($this->tmpDir . '/upgraded.epub');
        $errors = array_filter($reopened->validate(), static fn (ValidationIssue $issue): bool => $issue->severity === ValidationIssue::ERROR);
        $this->assertSame([], array_map(strval(...), array_values($errors)));
        $this->assertSame('3.0', $reopened->getMetadata()->getVersion());
        $this->assertNotNull($reopened->getMetadata()->getProperty('dcterms:modified'));
        $this->assertEquals($entries, $reopened->getTableOfContents()->getEntries());
        $this->assertNotNull(array_values(array_filter($reopened->getManifest()->getItems(), static fn ($item): bool => $item->mediaType === 'application/x-dtbncx+xml'))[0] ?? null, 'The NCX stays.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function epub2Fixtures(): iterable
    {
        yield 'valid_1.epub' => ['valid_1.epub'];
        yield 'valid_3.epub' => ['valid_3.epub'];
    }

    public function testConvertsTheEpub2PackageMetadata(): void
    {
        $epubFile = $this->open($this->epub2Book());
        $metadata = $epubFile->getMetadata();

        $this->assertTrue($epubFile->upgradeToEpub3());

        $this->assertSame('3.0', $metadata->getVersion());
        $this->assertEquals(
            [new \PhpEpub\Contributor('Ann Author', 'aut', 'Author, Ann'), new \PhpEpub\Contributor('Ed Editor', 'edt', null)],
            [...$metadata->getCreators(), ...$metadata->getContributors()]
        );
        $this->assertSame('2020-05-06', $metadata->getDate());
        $this->assertSame('2019-01-02', $metadata->getProperty('dcterms:created'));
        $this->assertSame('2020-01-01', $metadata->getProperty('dcterms:issued'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $metadata->getProperty('dcterms:modified'));
        $this->assertSame(['2020-05-06'], $metadata->getDublinCoreValues('date'));
        $this->assertSame(['urn:isbn:9780000000002', 'doi:10.1000/182', 'plain-id', '0-306-40615-2'], array_slice($metadata->getIdentifiers(), 1));

        $epubFile->save($this->tmpDir . '/upgraded.epub');
        $opf = (new EpubFile($this->tmpDir . '/upgraded.epub'));
        $opf->load();
        $xml = $opf->getContentManager()->getContent('OEBPS/content.opf');
        $opf->cleanup();

        $this->assertDoesNotMatchRegularExpression('/<dc:[^>]*\sopf:/', $xml, 'EPUB 3 allows no opf:* attributes on Dublin Core elements.');
        $this->assertStringContainsString('property="role" scheme="marc:relators">aut<', $xml);
        $this->assertStringContainsString('property="file-as">Author, Ann<', $xml);
        $this->assertStringContainsString('property="identifier-type" scheme="onix:codelist5">15<', $xml);
        $this->assertStringContainsString('property="identifier-type" scheme="onix:codelist5">02<', $xml);
        $this->assertStringContainsString('property="identifier-type" scheme="onix:codelist5">06<', $xml);
        $this->assertSame(3, substr_count($xml, 'property="identifier-type"'), 'A UUID or unknown scheme has no identifier-type.');
    }

    public function testAddsTheNavigationDocumentAndKeepsTheNcxAndGuide(): void
    {
        $epubFile = $this->open($this->epub2Book());

        $epubFile->upgradeToEpub3();
        $epubFile->save($this->tmpDir . '/upgraded.epub');

        $reopened = EpubFile::open($this->tmpDir . '/upgraded.epub');
        $nav = $reopened->getManifest()->findByPath('OEBPS/nav.xhtml');
        $this->assertNotNull($nav);
        $this->assertSame('nav', $nav->properties);
        $this->assertSame('application/xhtml+xml', $nav->mediaType);
        $this->assertNotNull($reopened->getManifest()->findByPath('OEBPS/toc.ncx'));

        $toc = $reopened->getTableOfContents();
        $this->assertEquals(
            [new TocEntry('Chapter', 'OEBPS/text/chapter.xhtml', null, [new TocEntry('Section', 'OEBPS/text/chapter.xhtml', 's1')])],
            $toc->getEntries()
        );
        $this->assertEquals([new Landmark('cover', 'Front', 'OEBPS/text/chapter.xhtml'), new Landmark('bodymatter', 'Start', 'OEBPS/text/chapter.xhtml')], $toc->getLandmarks());
        $this->assertSame(
            ['cover', 'text', 'other.custom'],
            array_map(static fn (Landmark $reference): string => $reference->type, $reopened->getManifest()->getGuideReferences()),
            'The guide stays for EPUB 2 reading systems.'
        );
    }

    public function testMarksTheCoverAndTheNeededContentProperties(): void
    {
        $epubFile = $this->open($this->epub2Book());

        $epubFile->upgradeToEpub3();

        $manifest = $epubFile->getManifest();
        $this->assertSame('cover-image', $manifest->get('cover')?->properties);
        $this->assertSame('svg mathml scripted remote-resources', $manifest->get('rich')?->properties);
        $this->assertSame('', $manifest->get('chapter')?->properties);
        $this->assertSame('cover', $epubFile->getMetadata()->getMeta('cover'), 'The EPUB 2 cover meta stays.');
    }

    public function testTheCoverOfAnEpub2GuideIsFoundThroughItsPage(): void
    {
        $book = $this->epub2Book();
        $opf = str_replace(
            ['<meta name="cover" content="cover"/>', 'title="Front" href="text/chapter.xhtml"'],
            ['', 'title="Front" href="text/rich.xhtml"'],
            (string) $book->getFile('OEBPS/content.opf')
        );
        $book->withFile('OEBPS/text/rich.xhtml', str_replace('<svg', '<img src="../images/cover.png" alt=""/><svg', (string) $book->getFile('OEBPS/text/rich.xhtml')));
        $epubFile = $this->open($book->withFile('OEBPS/content.opf', $opf));

        $epubFile->upgradeToEpub3();

        $this->assertSame('cover-image', $epubFile->getManifest()->get('cover')?->properties);
    }

    public function testAnEpub3BookIsLeftAlone(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $before = $epubFile->getContentManager()->getContent('EPUB/package.opf');

        $this->assertFalse($epubFile->upgradeToEpub3());

        $this->assertFalse($epubFile->getMetadata()->isModified());
        $this->assertFalse($epubFile->getManifest()->isModified());
        $this->assertSame($before, $epubFile->getContentManager()->getContent('EPUB/package.opf'));
    }

    public function testNeedsALoadedBook(): void
    {
        $epubFile = new EpubFile($this->tmpDir . '/missing.epub');

        $this->expectException(Exception::class);
        $epubFile->upgradeToEpub3();
    }

    public function testSkipsUnlinkedNcxEntriesAndNamesUntitledOnes(): void
    {
        $ncx = '<?xml version="1.0" encoding="UTF-8"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head/><docTitle><text>T</text></docTitle><navMap>'
            . '<navPoint id="a" playOrder="1"><navLabel><text>Remote</text></navLabel><content src="https://example.com/"/></navPoint>'
            . '<navPoint id="b" playOrder="2"><navLabel><text>Part</text></navLabel><content src="https://example.com/part"/>'
            . '<navPoint id="c" playOrder="3"><navLabel><text/></navLabel><content src="text/chapter.xhtml#s1"/></navPoint></navPoint>'
            . '</navMap></ncx>';
        $epubFile = $this->open($this->epub2Book()->withFile('OEBPS/toc.ncx', $ncx));

        $epubFile->upgradeToEpub3();

        $this->assertEquals(
            [new TocEntry('Part', '', null, [new TocEntry('chapter', 'OEBPS/text/chapter.xhtml', 's1')])],
            $epubFile->getTableOfContents()->getEntries()
        );
    }

    public function testBuildsTheNavigationFromTheReadingOrderWithoutAnNcx(): void
    {
        $book = $this->epub2Book()->withoutFile('OEBPS/toc.ncx');
        $opf = (string) preg_replace('#<item id="ncx"[^>]*/>#', '', (string) $book->getFile('OEBPS/content.opf'));
        $opf = str_replace('<itemref idref="rich"/>', '<itemref idref="rich" linear="no"/><itemref idref="style"/>', str_replace(' toc="ncx"', '', $opf));
        $epubFile = $this->open($book->withFile('OEBPS/content.opf', $opf));

        $epubFile->upgradeToEpub3();

        $this->assertEquals([new TocEntry('chapter', 'OEBPS/text/chapter.xhtml')], $epubFile->getTableOfContents()->getEntries());
    }

    public function testKeepsAnExistingNavigationDocumentAndAvoidsTakenNames(): void
    {
        $book = $this->epub2Book()->withFile('OEBPS/nav.xhtml', 'taken');
        $epubFile = $this->open($book);
        $epubFile->upgradeToEpub3();
        $this->assertNotNull($epubFile->getManifest()->findByPath('OEBPS/nav-2.xhtml'));
        $this->assertSame('taken', $epubFile->getContentManager()->getContent('OEBPS/nav.xhtml'));

        $opf = str_replace('<item id="ncx"', '<item id="old-nav" href="text/chapter.xhtml" media-type="application/xhtml+xml" properties="nav"/><item id="ncx"', (string) $this->epub2Book()->getFile('OEBPS/content.opf'));
        $withNav = $this->open($this->epub2Book()->withFile('OEBPS/content.opf', $opf));
        $withNav->upgradeToEpub3();
        $this->assertNull($withNav->getManifest()->findByPath('OEBPS/nav.xhtml'));
    }

    public function testFailsWhenTheNavigationCannotBeWritten(): void
    {
        $epubFile = $this->open($this->epub2Book());
        // The document would go into a directory that does not exist.
        $paths = new class extends PathResolver {
            public function resolve(string $rootDirectory, string $path): string
            {
                return str_ends_with($path, 'nav.xhtml') ? $rootDirectory . '/missing/nav.xhtml' : parent::resolve($rootDirectory, $path);
            }
        };
        $toc = new TableOfContents((string) $epubFile->getTempDir(), $epubFile->getManifest(), new XmlParser(), $paths);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to write the navigation document: OEBPS/nav.xhtml');
        $toc->createNavigation('Title', 'en');
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }

    private function epub2Book(): EpubBuilder
    {
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">
    <dc:identifier id="uid" opf:scheme="UUID">urn:uuid:7c1f0e2a-4b3d-4e5f-8a9b-0c1d2e3f4a5c</dc:identifier>
    <dc:identifier opf:scheme="ISBN">urn:isbn:9780000000002</dc:identifier>
    <dc:identifier opf:scheme="DOI">doi:10.1000/182</dc:identifier>
    <dc:identifier opf:scheme="ISBN">plain-id</dc:identifier>
    <dc:identifier opf:scheme="isbn">0-306-40615-2</dc:identifier>
    <dc:title>Upgrade Me</dc:title>
    <dc:creator opf:role="aut" opf:file-as="Author, Ann">Ann Author</dc:creator>
    <dc:contributor opf:role="edt">Ed Editor</dc:contributor>
    <dc:language>en</dc:language>
    <dc:date opf:event="modification">2020-01-02</dc:date>
    <dc:date opf:event="creation">2019-01-02</dc:date>
    <dc:date opf:event="creation">2019-12-31</dc:date>
    <dc:date opf:event="issued">2020-01-01</dc:date>
    <dc:date opf:event="publication">2020-05-06</dc:date>
    <dc:date>2021-01-01</dc:date>
    <dc:date opf:event="unknown">2018-01-01</dc:date>
    <meta name="cover" content="cover"/>
  </metadata>
  <manifest>
    <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
    <item id="chapter" href="text/chapter.xhtml" media-type="application/xhtml+xml"/>
    <item id="rich" href="text/rich.xhtml" media-type="application/xhtml+xml"/>
    <item id="cover" href="images/cover.png" media-type="image/png"/>
    <item id="style" href="css/style.css" media-type="text/css"/>
  </manifest>
  <spine toc="ncx">
    <itemref idref="chapter"/>
    <itemref idref="rich"/>
  </spine>
  <guide>
    <reference type="cover" title="Front" href="text/chapter.xhtml"/>
    <reference type="text" title="Start" href="text/chapter.xhtml"/>
    <reference type="other.custom" title="Custom" href="text/chapter.xhtml"/>
  </guide>
</package>
XML;
        $ncx = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
  <head><meta name="dtb:uid" content="urn:uuid:7c1f0e2a-4b3d-4e5f-8a9b-0c1d2e3f4a5c"/></head>
  <docTitle><text>Upgrade Me</text></docTitle>
  <navMap>
    <navPoint id="p1" playOrder="1"><navLabel><text>Chapter</text></navLabel><content src="text/chapter.xhtml"/>
      <navPoint id="p2" playOrder="2"><navLabel><text>Section</text></navLabel><content src="text/chapter.xhtml#s1"/></navPoint>
    </navPoint>
  </navMap>
</ncx>
XML;
        $rich = '<?xml version="1.0" encoding="UTF-8"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>Rich</title></head><body>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'
            . '<math xmlns="http://www.w3.org/1998/Math/MathML"><mi>x</mi></math>'
            . '<script type="text/javascript">var a;</script><img src="https://example.com/a.png" alt=""/></body></html>';

        return (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('OEBPS/content.opf')
            ->withFile('OEBPS/content.opf', $opf)
            ->withFile('OEBPS/toc.ncx', $ncx)
            ->withFile('OEBPS/text/chapter.xhtml', '<?xml version="1.0" encoding="UTF-8"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>Chapter</title></head><body><h1 id="s1">Chapter</h1></body></html>')
            ->withFile('OEBPS/text/rich.xhtml', $rich)
            ->withFile('OEBPS/images/cover.png', (string) base64_decode(EpubBuilder::PNG, true))
            ->withFile('OEBPS/css/style.css', 'p { margin: 0; }');
    }
}
