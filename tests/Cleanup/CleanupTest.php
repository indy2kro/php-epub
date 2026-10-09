<?php

declare(strict_types=1);

namespace PhpEpub\Test\Cleanup;

use PhpEpub\Cleanup\Cleanup;
use PhpEpub\Cleanup\CleanupAction;
use PhpEpub\Cleanup\CleanupOptions;
use PhpEpub\Cleanup\CleanupPreset;
use PhpEpub\Cleanup\CleanupReport;
use PhpEpub\Cleanup\ImageRecompressor;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\ManifestItem;
use PhpEpub\Test\Support\CleanupBook;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

final class CleanupTest extends TestCase
{
    private string $tmpDir;

    private ?EpubFile $book = null;

    protected function setUp(): void
    {
        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cleanup';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->book?->cleanup();
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return $this->book = CleanupBook::open($builder, $this->tmpDir);
    }

    private function read(string $path): string
    {
        return $this->book?->getContentManager()->getContent($path) ?? '';
    }

    private function properties(string $id): string
    {
        $item = $this->book?->getManifest()->get($id);

        return $item instanceof ManifestItem ? $item->properties : '';
    }

    private function has(string $path): bool
    {
        return in_array($path, $this->book?->getContentManager()->getContentPaths() ?? [], true);
    }

    public function testRemovesUnreferencedItemsButNeverTheEssentials(): void
    {
        $builder = CleanupBook::builder(
            <<<XML
<item id="cover" href="cover.png" media-type="image/png" properties="cover-image"/>
<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
<item id="used" href="used.png" media-type="image/png"/>
<item id="orphan" href="orphan.png" media-type="image/png"/>
<item id="orphandoc" href="orphan.xhtml" media-type="application/xhtml+xml"/>
XML,
            [
                'EPUB/cover.png' => 'cover',
                'EPUB/toc.ncx' => '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><navMap><navPoint id="n" playOrder="1"><navLabel><text>C</text></navLabel><content src="chapter.xhtml"/></navPoint></navMap></ncx>',
                'EPUB/used.png' => 'used',
                'EPUB/orphan.png' => 'orphan',
                'EPUB/orphan.xhtml' => EpubBuilder::xhtml('Orphan', '<p>x</p>'),
            ],
            chapterBody: '<img src="used.png" alt=""/>'
        );
        $book = $this->open($builder);

        $report = (new Cleanup($book))->run(new CleanupOptions(removeUnreferenced: true));

        $action = $report->getAction(CleanupAction::UNREFERENCED);
        $this->assertInstanceOf(\PhpEpub\Cleanup\CleanupAction::class, $action);
        $this->assertSame(['EPUB/orphan.png', 'EPUB/orphan.xhtml'], $action->files);
        $this->assertSame(strlen('orphan') + strlen(EpubBuilder::xhtml('Orphan', '<p>x</p>')), $action->bytesBefore);
        $this->assertSame(0, $action->bytesAfter);
        $this->assertSame($action->bytesBefore, $report->bytesBefore - $report->bytesAfter);
        foreach (['EPUB/cover.png', 'EPUB/toc.ncx', 'EPUB/used.png', 'EPUB/nav.xhtml', 'EPUB/chapter.xhtml'] as $kept) {
            $this->assertTrue($this->has($kept), $kept);
        }
        $this->assertFalse($this->has('EPUB/orphan.png'));
        $this->assertNull($book->getManifest()->findByPath('EPUB/orphan.xhtml'));
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $book->getManifest()->get('cover'));
        $this->assertSame([], array_map(strval(...), $book->validate()));
    }

    public function testDryRunReportsButChangesNothing(): void
    {
        $builder = CleanupBook::builder(
            '<item id="orphan" href="orphan.png" media-type="image/png"/>',
            ['EPUB/orphan.png' => 'orphan', 'EPUB/stray.txt' => 'stray'],
            chapterBody: '<script>var a;</script>'
        );
        $book = $this->open($builder);
        $before = $book->getContentManager()->getContentPaths();

        $report = (new Cleanup($book))->run(new CleanupOptions(removeUnreferenced: true, removeStrayFiles: true, stripScripts: true, dryRun: true));

        $this->assertTrue($report->dryRun);
        $this->assertSame(['EPUB/chapter.xhtml', 'EPUB/orphan.png', 'EPUB/stray.txt'], $report->getFiles());
        $this->assertSame($before, $book->getContentManager()->getContentPaths());
        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $book->getManifest()->findByPath('EPUB/orphan.png'));
        $this->assertStringContainsString('<script>', $this->read('EPUB/chapter.xhtml'));
    }

    public function testRemovesStrayFilesButKeepsRequiredAndReferencedOnes(): void
    {
        $builder = CleanupBook::builder(
            '',
            [
                'EPUB/stray.txt' => 'stray',
                'EPUB/junk/deep/a.bin' => 'junk',
                '__MACOSX/._x' => 'mac',
                'META-INF/extra.xml' => '<x/>',
                'EPUB/loose.png' => 'png',
            ],
            chapterBody: '<img src="loose.png" alt=""/>'
        );
        $book = $this->open($builder);

        $report = (new Cleanup($book))->run(new CleanupOptions(removeStrayFiles: true));

        $this->assertSame(['EPUB/junk/deep/a.bin', 'EPUB/stray.txt', '__MACOSX/._x'], $report->getAction(CleanupAction::STRAY_FILES)?->files);
        foreach (['mimetype', 'META-INF/container.xml', 'META-INF/extra.xml', 'EPUB/package.opf', 'EPUB/loose.png', 'EPUB/chapter.xhtml'] as $kept) {
            $this->assertTrue($this->has($kept), $kept);
        }
        $this->assertDirectoryDoesNotExist($book->getTempDir() . '/EPUB/junk');
        $this->assertDirectoryDoesNotExist($book->getTempDir() . '/__MACOSX');
    }

    public function testStrayFilesAreKeptInABookWithSeveralRootfiles(): void
    {
        $builder = CleanupBook::builder('', ['EPUB/stray.txt' => 'stray'])->withFile('META-INF/container.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">
  <rootfiles>
    <rootfile full-path="EPUB/package.opf" media-type="application/oebps-package+xml"/>
    <rootfile full-path="OTHER/package.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
XML);
        $book = $this->open($builder);

        $action = (new Cleanup($book))->run(new CleanupOptions(removeStrayFiles: true))->getAction(CleanupAction::STRAY_FILES);

        $this->assertTrue($action?->skipped);
        $this->assertTrue($this->has('EPUB/stray.txt'));
    }

    public function testStripsScriptsEventHandlersAndJavascriptUrls(): void
    {
        $body = '<img src="pic.svg" alt=""/><p onclick="evil()" id="p">Hi <a href=" JaVa&#10;script:alert(1)">x</a> <a href="https://example.com/">ok</a></p>'
            . '<script>alert(1)</script><script src="app.js"></script>'
            . '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"><script>y()</script><rect width="1" height="1"/></svg>';
        $builder = CleanupBook::builder(
            '<item id="js" href="app.js" media-type="text/javascript"/><item id="svg" href="pic.svg" media-type="image/svg+xml"/>',
            [
                'EPUB/app.js' => 'alert(1)',
                'EPUB/pic.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>z()</script><circle r="1" onmouseover="q()"/></svg>',
            ],
            chapterBody: $body
        );
        $book = $this->open($builder);
        $book->getManifest()->addProperty('chapter', 'scripted');
        $this->assertStringContainsString('scripted', $this->properties('chapter'));

        $report = (new Cleanup($book))->run(new CleanupOptions(stripScripts: true, removeUnreferenced: true));

        $chapter = $this->read('EPUB/chapter.xhtml');
        $this->assertStringNotContainsString('script', strtolower($chapter));
        $this->assertStringNotContainsString('onclick', $chapter);
        $this->assertStringNotContainsString('onload', $chapter);
        $this->assertStringContainsString('https://example.com/', $chapter);
        $this->assertStringContainsString('id="p"', $chapter);
        $this->assertStringNotContainsString('<script', $this->read('EPUB/pic.svg'));
        $this->assertStringNotContainsString('onmouseover', $this->read('EPUB/pic.svg'));
        $this->assertStringNotContainsString('scripted', $this->properties('chapter'));
        $this->assertSame(['EPUB/chapter.xhtml', 'EPUB/pic.svg'], $report->getAction(CleanupAction::SCRIPTS)?->files);
        // The script file lost its only reference.
        $this->assertSame(['EPUB/app.js'], $report->getAction(CleanupAction::UNREFERENCED)?->files);
    }

    public function testMalformedDocumentsAreLeftAloneWhenStrippingScripts(): void
    {
        $builder = CleanupBook::builder('<item id="broken" href="broken.xhtml" media-type="application/xhtml+xml"/>', ['EPUB/broken.xhtml' => '<html><script>x'], '<itemref idref="broken"/>');
        $book = $this->open($builder);

        $report = (new Cleanup($book))->run(new CleanupOptions(stripScripts: true, removeRemoteReferences: true));

        $this->assertSame([], $report->getFiles());
        $this->assertSame('<html><script>x', $this->read('EPUB/broken.xhtml'));
    }

    public function testRemovesRemoteReferencesAndTheRemoteResourcesProperty(): void
    {
        $css = "@import url(https://fonts.example.com/f.css);\n@import 'local.css';\n"
            . "@font-face { font-family: R; src: url(https://cdn.example.com/r.woff2); }\n"
            . "p { color: red; background: url(//cdn.example.com/bg.png); margin: 0 }\n"
            . "h1 { background: url(local.png) }";
        $body = '<p style="background: url(http://example.com/x.png); color: blue">t</p>'
            . '<img src="https://example.com/a.png" alt=""/><img src="local.png" alt=""/>'
            . '<picture><source srcset="https://example.com/b.webp 1x, local.png 2x"/></picture>'
            . '<a href="https://example.com/">link</a>';
        $builder = CleanupBook::builder(
            '<item id="css" href="s.css" media-type="text/css"/><item id="local" href="local.css" media-type="text/css"/><item id="png" href="local.png" media-type="image/png"/>',
            ['EPUB/s.css' => $css, 'EPUB/local.css' => 'p{}', 'EPUB/local.png' => 'png'],
            chapterBody: $body
        )->withFile('EPUB/chapter.xhtml', str_replace('</head>', '<link rel="stylesheet" href="https://example.com/x.css"/><link rel="stylesheet" href="s.css"/></head>', EpubBuilder::xhtml('Chapter', $body)));
        $book = $this->open($builder);
        $book->getManifest()->addProperty('chapter', 'remote-resources');

        $report = (new Cleanup($book))->run(new CleanupOptions(removeRemoteReferences: true));

        $chapter = $this->read('EPUB/chapter.xhtml');
        $this->assertStringNotContainsString('example.com/x.css', $chapter);
        $this->assertStringNotContainsString('example.com/a.png', $chapter);
        $this->assertStringNotContainsString('b.webp', $chapter);
        $this->assertStringNotContainsString('x.png', $chapter);
        $this->assertStringContainsString('color: blue', $chapter);
        $this->assertStringContainsString('href="s.css"', $chapter);
        $this->assertStringContainsString('src="local.png"', $chapter);
        $this->assertStringContainsString('local.png 2x', $chapter);
        $this->assertStringContainsString('<a href="https://example.com/">', $chapter);
        $stylesheet = $this->read('EPUB/s.css');
        $this->assertStringNotContainsString('example.com', $stylesheet);
        $this->assertStringContainsString("@import 'local.css';", $stylesheet);
        $this->assertStringContainsString('h1 { background: url(local.png) }', $stylesheet);
        $this->assertStringContainsString('margin: 0', $stylesheet);
        $this->assertStringNotContainsString('remote-resources', $this->properties('chapter'));
        $this->assertSame(['EPUB/chapter.xhtml', 'EPUB/s.css'], $report->getAction(CleanupAction::REMOTE_REFERENCES)?->files);
    }

    public function testRemovesOnlyUnusedFontsAndTheirEncryptionEntries(): void
    {
        $encryption = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#">
  <enc:EncryptedData><enc:EncryptionMethod Algorithm="http://www.idpf.org/2008/embedding"/><enc:CipherData><enc:CipherReference URI="EPUB/fonts/unused.otf"/></enc:CipherData></enc:EncryptedData>
  <enc:EncryptedData><enc:EncryptionMethod Algorithm="http://www.idpf.org/2008/embedding"/><enc:CipherData><enc:CipherReference URI="EPUB/fonts/used.otf"/></enc:CipherData></enc:EncryptedData>
</encryption>
XML;
        $builder = CleanupBook::builder(
            <<<XML
<item id="css" href="s.css" media-type="text/css"/>
<item id="used" href="fonts/used.otf" media-type="font/otf"/>
<item id="unused" href="fonts/unused.otf" media-type="font/otf"/>
<item id="orphan" href="orphan.png" media-type="image/png"/>
XML,
            [
                'EPUB/s.css' => '@font-face { font-family: U; src: url(fonts/used.otf) }',
                'EPUB/fonts/used.otf' => 'used',
                'EPUB/fonts/unused.otf' => 'unused',
                'EPUB/orphan.png' => 'orphan',
                'META-INF/encryption.xml' => $encryption,
            ]
        )->withFile('EPUB/chapter.xhtml', EpubBuilder::xhtml('Chapter', '<p>x</p>', 's.css'));
        $book = $this->open($builder);

        $report = (new Cleanup($book))->run(new CleanupOptions(removeUnusedFonts: true));

        $this->assertSame(['EPUB/fonts/unused.otf'], $report->getAction(CleanupAction::UNUSED_FONTS)?->files);
        $this->assertNull($report->getAction(CleanupAction::UNREFERENCED));
        $this->assertTrue($this->has('EPUB/orphan.png'));
        $this->assertTrue($this->has('EPUB/fonts/used.otf'));
        $encryptionXml = $this->read('META-INF/encryption.xml');
        $this->assertStringNotContainsString('unused.otf', $encryptionXml);
        $this->assertStringContainsString('used.otf', $encryptionXml);
    }

    public function testRefusesDrmProtectedBooks(): void
    {
        $book = $this->open(CleanupBook::builder('', ['META-INF/rights.xml' => '<rights/>']));

        $this->expectException(Exception::class);
        (new Cleanup($book))->run(new CleanupOptions(removeUnreferenced: true));
    }

    public function testImageRecompressionIsReportedAsSkippedWithoutGd(): void
    {
        if (ImageRecompressor::isAvailable()) {
            $this->markTestSkipped('GD is available.');
        }

        $book = $this->open(CleanupBook::builder());

        $action = (new Cleanup($book))->run(new CleanupOptions(recompressImages: true))->getAction(CleanupAction::IMAGES);

        $this->assertTrue($action?->skipped);
    }

    public function testPresetsAndValidation(): void
    {
        $light = CleanupOptions::preset(CleanupPreset::Light);
        $this->assertTrue($light->removeUnreferenced && $light->removeStrayFiles && $light->maxDeflate);
        $this->assertFalse($light->recompressImages);

        $balanced = CleanupOptions::preset(CleanupPreset::Balanced);
        $this->assertTrue($balanced->recompressImages);
        $this->assertSame([1600, 1600, 80, false], [$balanced->maxImageWidth, $balanced->maxImageHeight, $balanced->jpegQuality, $balanced->convertOpaquePngToJpeg]);

        $strong = CleanupOptions::preset(CleanupPreset::Strong);
        $this->assertSame([1200, 1200, 65, true], [$strong->maxImageWidth, $strong->maxImageHeight, $strong->jpegQuality, $strong->convertOpaquePngToJpeg]);
        $this->assertTrue($strong->withDryRun()->dryRun);
        $this->assertSame(1200, $strong->withDryRun()->maxImageWidth);

        $this->expectException(Exception::class);
        new CleanupOptions(jpegQuality: 0);
    }

    public function testInvalidImageLimitsAreRefused(): void
    {
        $this->expectException(Exception::class);
        new CleanupOptions(maxImageWidth: 0);
    }

    public function testCompressSavesWithMaximumDeflateAndReportsArchiveSizes(): void
    {
        $text = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 400);
        $builder = CleanupBook::builder('<item id="orphan" href="orphan.txt" media-type="text/plain"/>', ['EPUB/orphan.txt' => 'orphan'], chapterBody: '<p>' . $text . '</p>');
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'out.epub';
        $builder->buildEpub($path);
        $original = (int) filesize($path);

        $book = EpubFile::open($path);
        $report = $book->compress(CleanupPreset::Light);
        $book->cleanup();

        $this->assertInstanceOf(CleanupReport::class, $report);
        $this->assertSame($original, $report->archiveBytesBefore);
        $this->assertSame(filesize($path), $report->archiveBytesAfter);
        $this->assertLessThan($original, $report->archiveBytesAfter);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $this->assertSame('mimetype', $zip->getNameIndex(0));
        $this->assertSame(\ZipArchive::CM_STORE, $zip->statIndex(0)['comp_method'] ?? null);
        $this->assertFalse($zip->locateName('EPUB/orphan.txt'));
        $zip->close();

        $reopened = EpubFile::open($path);
        $this->assertSame([], array_map(strval(...), $reopened->validate()));
        $reopened->cleanup();
    }

    public function testCompressDryRunWritesNothing(): void
    {
        $path = CleanupBook::builder('<item id="orphan" href="orphan.txt" media-type="text/plain"/>', ['EPUB/orphan.txt' => 'orphan'])
            ->buildEpub($this->tmpDir . DIRECTORY_SEPARATOR . 'dry.epub');
        $hash = md5_file($path);

        $book = EpubFile::open($path);
        $report = $book->compress(CleanupOptions::preset(CleanupPreset::Light)->withDryRun());
        $book->cleanup();

        $this->assertTrue($report->dryRun);
        $this->assertSame(['EPUB/orphan.txt'], $report->getFiles());
        $this->assertSame($hash, md5_file($path));
    }

    public function testStripsSrcdocAndScriptingAnimationsAndReportsUnparsableDocuments(): void
    {
        $body = '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;" title="t"></iframe>'
            . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a id="l" xlink:href="#"><text>x</text>'
            . '<animate attributeName="xlink:href" values="javascript:alert(1)"/><set attributeName="href" to=" java&#10;script:alert(2)"/><animate attributeName="x" values="0;1"/></a></svg>';
        $builder = CleanupBook::builder(
            '<item id="broken" href="broken.xhtml" media-type="application/xhtml+xml"/>',
            ['EPUB/broken.xhtml' => '<html><script>x'],
            '<itemref idref="broken"/>',
            $body
        );
        $book = $this->open($builder);

        $report = (new Cleanup($book))->run(new CleanupOptions(stripScripts: true));

        $chapter = $this->read('EPUB/chapter.xhtml');
        $this->assertStringNotContainsString('srcdoc', $chapter);
        $this->assertStringNotContainsString('javascript', strtolower($chapter));
        $this->assertStringNotContainsString('script:', strtolower($chapter));
        $this->assertStringContainsString('values="0;1"', $chapter);
        $action = $report->getAction(CleanupAction::SCRIPTS);
        $this->assertInstanceOf(\PhpEpub\Cleanup\CleanupAction::class, $action);
        $this->assertSame(['EPUB/chapter.xhtml'], $action->files);
        $this->assertStringContainsString('EPUB/broken.xhtml', $action->note);
    }
}
