<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\ContentManager;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Manifest;
use PhpEpub\ManifestItem;
use PhpEpub\Spine;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\XmlParser;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * ContentManager keeping the manifest and spine in sync, and EpubFile persisting it.
 */
final class ContentManagerPackageTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'package';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testAddContentCreatesDirectoriesAndManifestItem(): void
    {
        [$contentManager, $manifest] = $this->open();

        $contentManager->addContent('EPUB/text/new.xhtml', '<html/>');

        $this->assertStringEqualsFile($this->tmpDir . '/book/EPUB/text/new.xhtml', '<html/>');
        $this->assertSame('text/new.xhtml', $manifest->findByPath('EPUB/text/new.xhtml')?->href);
    }

    public function testAddContentDoesNotDuplicateExistingManifestItems(): void
    {
        [$contentManager, $manifest] = $this->open();

        $contentManager->addContent('EPUB/chapter.xhtml', '<html>replaced</html>');

        $this->assertCount(1, $manifest->getItems());
        $this->assertFalse($manifest->isModified());
    }

    public function testAddContentLeavesContainerFilesOutOfTheManifest(): void
    {
        [$contentManager, $manifest] = $this->open();

        $contentManager->addContent('META-INF/encryption.xml', '<encryption/>');

        $this->assertCount(1, $manifest->getItems());
    }

    public function testThePackageDocumentCannotBeChangedAsContent(): void
    {
        [$contentManager] = $this->open();
        $opfPath = $this->tmpDir . '/book/EPUB/package.opf';
        $original = (string) file_get_contents($opfPath);

        $attempts = [
            'add' => static fn () => $contentManager->addContent('EPUB/package.opf', '<package/>'),
            'update' => static fn () => $contentManager->updateContent('EPUB/./package.opf', '<package/>'),
            'delete' => static fn () => $contentManager->deleteContent('EPUB/package.opf'),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("Expected {$label} of the package document to be refused.");
            } catch (Exception $exception) {
                $this->assertStringContainsString('package document', $exception->getMessage(), $label);
            }
        }

        $this->assertStringEqualsFile($opfPath, $original);
        $this->assertSame($original, $contentManager->getContent('EPUB/package.opf'));
    }

    public function testAddContentFailsWhenAFileBlocksTheDirectory(): void
    {
        [$contentManager] = $this->open();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to create directory:');

        $contentManager->addContent('EPUB/chapter.xhtml/nested.xhtml', '<html/>');
    }

    public function testGetManifestBeforeLoadThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('EPUB file must be loaded before accessing manifest.');

        (new EpubFile($this->tmpDir . '/missing.epub'))->getManifest();
    }

    public function testDeleteContentRemovesManifestItemAndSpineEntry(): void
    {
        [$contentManager, $manifest, $spine] = $this->open();

        $contentManager->deleteContent('EPUB/chapter.xhtml');

        $this->assertFileDoesNotExist($this->tmpDir . '/book/EPUB/chapter.xhtml');
        $this->assertNull($manifest->get('chapter'));
        $this->assertSame([], $spine->get());
    }

    public function testGetContentPathsReturnsSortedBookRelativePaths(): void
    {
        [$contentManager] = $this->open();

        $this->assertSame(
            ['EPUB/chapter.xhtml', 'EPUB/package.opf', 'META-INF/container.xml', 'mimetype'],
            $contentManager->getContentPaths()
        );
    }

    public function testEpubFileSavePersistsContentAndSpineChanges(): void
    {
        $epubPath = EpubBuilder::minimal()->buildEpub($this->tmpDir . '/in.epub');
        $epubFile = new EpubFile($epubPath);
        $epubFile->load();

        $epubFile->getContentManager()->addContent('EPUB/text/appendix.xhtml', '<html/>');
        $appendix = $epubFile->getManifest()->findByPath('EPUB/text/appendix.xhtml');
        $this->assertInstanceOf(ManifestItem::class, $appendix);
        $epubFile->getSpine()->add($appendix->id);
        $epubFile->save($this->tmpDir . '/out.epub');

        $reloaded = new EpubFile($this->tmpDir . '/out.epub');
        $reloaded->load();

        $this->assertSame(['chapter', $appendix->id], $reloaded->getSpine()->get());
        $this->assertSame('<html/>', $reloaded->getContentManager()->getContent('EPUB/text/appendix.xhtml'));
        $this->assertNotSame([], $this->modifiedDates($reloaded));
    }

    public function testLoadingTwiceRemovesThePreviousTempDirectory(): void
    {
        $epubFile = new EpubFile(EpubBuilder::minimal()->buildEpub($this->tmpDir . '/in.epub'));
        $epubFile->load();
        $first = (string) $epubFile->getTempDir();

        $epubFile->load();

        $this->assertDirectoryDoesNotExist($first);
        $this->assertDirectoryExists((string) $epubFile->getTempDir());
    }

    public function testAddedXhtmlGetsTheEpub3PropertiesItsContentNeeds(): void
    {
        [$contentManager, $manifest] = $this->open();

        $contentManager->addContent('EPUB/drawing.xhtml', self::xhtml(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg><math xmlns="http://www.w3.org/1998/Math/MathML"><mi>x</mi></math>'
        ));
        $contentManager->addContent('EPUB/active.xhtml', self::xhtml(
            '<script>run()</script><audio src="https://example.com/a.mp3"/><a href="https://example.com/">Link</a>'
        ));
        $contentManager->addContent('EPUB/form.xhtml', self::xhtml('<form><input/></form>'));
        $contentManager->addContent('EPUB/plain.xhtml', self::xhtml('<p>Text</p><a href="https://example.com/">Link</a>'));

        $this->assertSame('svg mathml', $manifest->findByPath('EPUB/drawing.xhtml')?->properties);
        $this->assertSame('scripted remote-resources', $manifest->findByPath('EPUB/active.xhtml')?->properties);
        $this->assertSame('scripted', $manifest->findByPath('EPUB/form.xhtml')?->properties);
        $this->assertSame('', $manifest->findByPath('EPUB/plain.xhtml')?->properties);
    }

    public function testUpdatedXhtmlPropertiesFollowItsContentAndKeepOthers(): void
    {
        [$contentManager, $manifest] = $this->open();
        $manifest->addProperty('chapter', 'nav');
        $properties = static fn (): ?string => $manifest->get('chapter')?->properties;

        $contentManager->updateContent('EPUB/chapter.xhtml', self::xhtml('<svg xmlns="http://www.w3.org/2000/svg"/>'));
        $this->assertSame('nav svg', $properties());

        $contentManager->updateContent('EPUB/chapter.xhtml', self::xhtml('<p>No drawing</p>'));
        $this->assertSame('nav', $properties());
    }

    public function testPropertiesAreLeftAloneForOtherFilesAndRefusedXhtml(): void
    {
        [$contentManager, $manifest] = $this->open();
        $manifest->addProperty('chapter', 'svg');

        try {
            $contentManager->updateContent('EPUB/chapter.xhtml', '<html><body><p>Broken');
            $this->fail('Expected the malformed XHTML to be refused.');
        } catch (Exception) {
            // The refused update changes neither the file nor its properties.
        }

        $contentManager->addContent('EPUB/image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>x()</script></svg>');

        $this->assertSame('svg', $manifest->get('chapter')?->properties);
        $this->assertStringContainsString('<p>Chapter</p>', $contentManager->getContent('EPUB/chapter.xhtml'));
        $this->assertSame('', $manifest->findByPath('EPUB/image.svg')?->properties);
    }

    public function testEpub2PackagesGetNoProperties(): void
    {
        $root = EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', str_replace('version="3.0"', 'version="2.0"', EpubBuilder::opf()))
            ->writeTo($this->tmpDir . '/book');
        $manifest = new Manifest((new XmlParser())->parse($root . '/EPUB/package.opf'), 'EPUB/package.opf');

        (new ContentManager($root, $manifest))->addContent('EPUB/drawing.xhtml', self::xhtml('<svg xmlns="http://www.w3.org/2000/svg"/>'));

        $this->assertSame('', $manifest->findByPath('EPUB/drawing.xhtml')?->properties);
    }

    public function testAddChapterWithInlineSvgSetsTheProperty(): void
    {
        $epubFile = EpubFile::open(EpubBuilder::epub3()->buildEpub($this->tmpDir . '/in.epub'));

        $item = $epubFile->addChapter('Drawing', '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');

        $this->assertSame('svg', $item->properties);
    }

    public function testMoveContentKeepsTheItemAndUpdatesEveryReference(): void
    {
        $opf = str_replace(
            ['<spine>', '</package>'],
            ['<spine toc="ncx">', '<guide><reference type="text" title="Start" href="chapter.xhtml"/></guide></package>'],
            EpubBuilder::opf(
                '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
                . '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
                . '<item id="font" href="font.otf" media-type="font/otf"/>'
            )
        );
        $epubFile = EpubFile::open(EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml('Nav', '<nav epub:type="toc"><ol><li><a href="chapter.xhtml#top">Chapter</a></li></ol></nav>'))
            ->withFile('EPUB/toc.ncx', '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head/><docTitle><text>T</text></docTitle><navMap>'
                . '<navPoint id="p1" playOrder="1"><navLabel><text>Chapter</text></navLabel><content src="chapter.xhtml#top"/></navPoint></navMap></ncx>')
            ->withFile('EPUB/font.otf', 'font')
            ->withFile('META-INF/encryption.xml', '<encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#">'
                . '<enc:EncryptedData><enc:EncryptionMethod Algorithm="http://www.idpf.org/2008/embedding"/>'
                . '<enc:CipherData><enc:CipherReference URI="EPUB/font.otf"/></enc:CipherData></enc:EncryptedData></encryption>')
            ->buildEpub($this->tmpDir . '/in.epub'));
        $content = $epubFile->getContentManager();

        $content->moveContent('EPUB/chapter.xhtml', 'EPUB/text/chapter one.xhtml');
        $content->moveContent('EPUB/font.otf', 'EPUB/fonts/body.otf');

        $chapter = $epubFile->getManifest()->get('chapter');
        $this->assertSame('text/chapter%20one.xhtml', $chapter?->href);
        $this->assertSame('EPUB/text/chapter one.xhtml', $chapter->path);
        $this->assertSame(['chapter'], $epubFile->getSpine()->get());
        $this->assertSame('EPUB/text/chapter one.xhtml', $epubFile->getManifest()->getGuidePath('text'));
        $this->assertEquals([new TocEntry('Chapter', 'EPUB/text/chapter one.xhtml', 'top')], $epubFile->getTableOfContents()->getEntries());
        $this->assertStringContainsString('src="text/chapter%20one.xhtml#top"', $content->getContent('EPUB/toc.ncx'));
        $this->assertStringContainsString('URI="EPUB/fonts/body.otf"', $content->getContent('META-INF/encryption.xml'));
        $this->assertSame('font', $content->getContent('EPUB/fonts/body.otf'));
        $this->assertNotContains('EPUB/chapter.xhtml', $content->getContentPaths());
    }

    public function testMoveContentRefusesToOverwriteOrToMoveThePackage(): void
    {
        [$contentManager] = $this->open();
        $contentManager->addContent('EPUB/other.xhtml', '<html/>');

        $refused = [
            ['EPUB/chapter.xhtml', 'EPUB/other.xhtml', 'already exists'],
            ['EPUB/missing.xhtml', 'EPUB/new.xhtml', 'does not exist'],
            ['EPUB/package.opf', 'EPUB/moved.opf', 'package document'],
            ['EPUB/chapter.xhtml', '../outside.xhtml', 'outside the EPUB'],
        ];

        foreach ($refused as [$from, $to, $message]) {
            try {
                $contentManager->moveContent($from, $to);
                $this->fail("Expected an exception for moving {$from} to {$to}.");
            } catch (Exception $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertFileExists($this->tmpDir . '/book/EPUB/chapter.xhtml');
    }

    public function testMovingTheNavigationDocumentKeepsItsLinksWorking(): void
    {
        $epubFile = EpubFile::open(EpubBuilder::epub3()->buildEpub($this->tmpDir . '/in.epub'));

        $epubFile->getContentManager()->moveContent('EPUB/nav.xhtml', 'EPUB/navigation/toc.xhtml');

        $this->assertEquals([new TocEntry('Chapter', 'EPUB/text/chapter.xhtml')], $epubFile->getTableOfContents()->getEntries());
        $this->assertStringContainsString('href="../text/chapter.xhtml"', $epubFile->getContentManager()->getContent('EPUB/navigation/toc.xhtml'));
    }

    public function testMoveContentWorksWhenTheNavigationAndEncryptionFilesAreBroken(): void
    {
        $epubFile = EpubFile::open(EpubBuilder::epub3()
            ->withFile('EPUB/nav.xhtml', '<html><body><nav')
            ->withFile('META-INF/encryption.xml', '<encryption')
            ->buildEpub($this->tmpDir . '/in.epub'));

        $epubFile->getContentManager()->moveContent('EPUB/text/chapter.xhtml', 'EPUB/chapter.xhtml');

        $this->assertSame('EPUB/chapter.xhtml', $epubFile->getManifest()->get('chapter')?->path);
        $this->assertSame('<encryption', $epubFile->getContentManager()->getContent('META-INF/encryption.xml'));
    }

    public function testMoveContentOfAFileOutsideTheManifest(): void
    {
        [$contentManager, $manifest] = $this->open();
        file_put_contents($this->tmpDir . '/book/EPUB/notes.txt', 'notes');

        $contentManager->moveContent('EPUB/notes.txt', 'EPUB/misc/notes.txt');

        $this->assertSame('notes', $contentManager->getContent('EPUB/misc/notes.txt'));
        $this->assertNull($manifest->findByPath('EPUB/misc/notes.txt'));
    }

    private static function xhtml(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>T</title></head>'
            . "<body>{$body}</body></html>";
    }

    /**
     * @return array{ContentManager, Manifest, Spine}
     */
    private function open(): array
    {
        $root = EpubBuilder::minimal()->writeTo($this->tmpDir . '/book');
        $opfXml = (new XmlParser())->parse($root . '/EPUB/package.opf');
        $manifest = new Manifest($opfXml, 'EPUB/package.opf');
        $spine = new Spine($opfXml, $manifest);

        return [new ContentManager($root, $manifest, $spine), $manifest, $spine];
    }

    /**
     * @return list<string>
     */
    private function modifiedDates(EpubFile $epubFile): array
    {
        $opf = (new XmlParser())->parse($epubFile->getMetadata()->getOpfFilePath());
        $opf->registerXPathNamespace('opf', 'http://www.idpf.org/2007/opf');

        return array_values(array_map(
            static fn (SimpleXMLElement $meta): string => (string) $meta,
            $opf->xpath("//opf:meta[@property='dcterms:modified']") ?: []
        ));
    }
}
