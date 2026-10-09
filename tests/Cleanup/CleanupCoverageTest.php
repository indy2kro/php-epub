<?php

declare(strict_types=1);

namespace PhpEpub\Test\Cleanup;

use PhpEpub\Cleanup\Cleanup;
use PhpEpub\Cleanup\CleanupAction;
use PhpEpub\Cleanup\CleanupOptions;
use PhpEpub\Cleanup\CleanupPreset;
use PhpEpub\Cleanup\ImageRecompressor;
use PhpEpub\Cleanup\ReferenceGraph;
use PhpEpub\EpubFile;
use PhpEpub\Test\Support\CleanupBook;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the cleanup classes: unusual input, damaged books and image formats.
 */
final class CleanupCoverageTest extends TestCase
{
    private string $tmpDir;

    private ?EpubFile $book = null;

    protected function setUp(): void
    {
        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cleanup-coverage';
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

    private function requireGd(): void
    {
        if (! ImageRecompressor::isAvailable()) {
            $this->markTestSkipped('The GD extension is not available.');
        }
    }

    public function testReportSavingsAndFileListAreSummed(): void
    {
        $book = $this->open(CleanupBook::builder('<item id="o" href="o.txt" media-type="text/plain"/>', ['EPUB/o.txt' => 'orphan']));

        $report = (new Cleanup($book))->run(new CleanupOptions(removeUnreferenced: true));

        $this->assertSame(6, $report->bytesSaved());
    }

    public function testStyleElementsAndInputsLoseTheirRemoteReferences(): void
    {
        $body = '<input type="image" src="https://example.com/b.png" alt="b"/><p>keep</p>';
        $builder = CleanupBook::builder(chapterBody: $body)->withFile('EPUB/chapter.xhtml', str_replace('</head>', '<style>@import url(https://example.com/f.css); p { color: red }</style></head>', EpubBuilder::xhtml('Chapter', $body)));
        $book = $this->open($builder);

        $report = (new Cleanup($book))->run(new CleanupOptions(removeRemoteReferences: true));

        $chapter = $this->read('EPUB/chapter.xhtml');
        $this->assertStringNotContainsString('example.com', $chapter);
        $this->assertStringContainsString('color: red', $chapter);
        $this->assertStringContainsString('<input', $chapter);
        $this->assertSame(['EPUB/chapter.xhtml'], $report->getAction(CleanupAction::REMOTE_REFERENCES)?->files);
    }

    public function testStrayFilesAreKeptWhenTheContainerCannotBeRead(): void
    {
        $book = $this->open(CleanupBook::builder('', ['EPUB/stray.txt' => 'stray']));
        unlink($book->getTempDir() . '/META-INF/container.xml');

        $action = (new Cleanup($book))->run(new CleanupOptions(removeStrayFiles: true))->getAction(CleanupAction::STRAY_FILES);

        $this->assertTrue($action?->skipped);
        $this->assertFileExists($book->getTempDir() . '/EPUB/stray.txt');
    }

    public function testUnusedFontsAreRemovedEvenWhenEncryptionXmlIsDamaged(): void
    {
        $book = $this->open(CleanupBook::builder(
            '<item id="f" href="f.otf" media-type="font/otf"/>',
            ['EPUB/f.otf' => 'font', 'META-INF/encryption.xml' => '<encryption']
        ));

        $report = (new Cleanup($book))->run(new CleanupOptions(removeUnusedFonts: true));

        $this->assertSame(['EPUB/f.otf'], $report->getAction(CleanupAction::UNUSED_FONTS)?->files);
        $this->assertSame('<encryption', $this->read('META-INF/encryption.xml'));
    }

    public function testGraphHandlesMissingFilesQueryOnlyReferencesAndRemoteItems(): void
    {
        $builder = CleanupBook::builder(
            '<item id="gone" href="gone.png" media-type="image/png"/><item id="remote" href="https://example.com/r.css" media-type="text/css"/><item id="js" href="app.js" media-type="text/javascript"/>',
            ['EPUB/app.js' => 'load("gone.png");'],
            chapterBody: '<script src="app.js"></script><a href="?page=2">next</a><img src="gone.png" alt=""/><style><!-- c --></style><script><!-- c --></script>'
        );
        $this->book = CleanupBook::open($builder, $this->tmpDir);

        $analysis = ReferenceGraph::forBook($this->book)->analyze();

        $this->assertTrue($analysis->isReachable('EPUB/gone.png'));
        $this->assertContains('EPUB/gone.png', $analysis->mentioned);
    }

    public function testCoverMetaGuideAndInlineCodeAreFollowed(): void
    {
        $builder = CleanupBook::builder(
            '<item id="cov" href="cov.png" media-type="image/png"/><item id="g" href="guide.xhtml" media-type="application/xhtml+xml"/><item id="a" href="a.png" media-type="image/png"/><item id="b" href="b.png" media-type="image/png"/><item id="c" href="c.png" media-type="image/png"/><item id="o" href="o.png" media-type="image/png"/>',
            ['EPUB/cov.png' => 'p', 'EPUB/guide.xhtml' => EpubBuilder::xhtml('G', '<p>g</p>'), 'EPUB/a.png' => 'p', 'EPUB/b.png' => 'p', 'EPUB/c.png' => 'p', 'EPUB/o.png' => 'p'],
            chapterBody: '<meta http-equiv="refresh" content="0; url=a.png"/><style>p { background: url(b.png) }</style><script>var x = "c.png";</script>',
            metadata: '<meta name="cover" content="cov"/>'
        );
        $opf = (string) $builder->getFile('EPUB/package.opf');
        $builder->withFile('EPUB/package.opf', str_replace('</package>', '<guide><reference type="text" title="G" href="guide.xhtml"/></guide></package>', $opf));
        $this->book = CleanupBook::open($builder, $this->tmpDir);

        $analysis = ReferenceGraph::forBook($this->book)->analyze();

        $this->assertSame(['EPUB/o.png'], $analysis->unreachable);
    }

    public function testASingleTransparentPixelPreventsTheConversion(): void
    {
        $this->requireGd();
        $png = $this->png(1000, 1000, false, true, 1);

        $result = (new ImageRecompressor())->recompress($png, null, null, 70, true);

        $this->assertNotSame('image/jpeg', $result['mediaType'] ?? null);
    }

    public function testImagesWithMissingFilesAreIgnored(): void
    {
        $book = $this->open(CleanupBook::builder('<item id="g" href="gone.png" media-type="image/png"/>', [], chapterBody: '<img src="gone.png" alt=""/>'));
        $this->requireGd();

        $action = (new Cleanup($book))->run(new CleanupOptions(recompressImages: true))->getAction(CleanupAction::IMAGES);

        $this->assertSame([], $action?->files);
    }

    public function testMemoryLimitUnitsAreUnderstood(): void
    {
        $this->requireGd();
        $image = base64_decode(EpubBuilder::PNG, true);
        $this->assertIsString($image);
        $recompressor = new ImageRecompressor();
        $limit = (string) ini_get('memory_limit');

        try {
            foreach (['4g', '4000000k', '4096m', '4294967296'] as $value) {
                ini_set('memory_limit', $value);
                $this->assertFalse($recompressor->exceedsLimits($image, null, null), $value);
            }
        } finally {
            ini_set('memory_limit', $limit);
        }

        $this->assertFalse($recompressor->exceedsLimits('not an image', null, null));
        $this->assertSame(0, $recompressor->pixels('not an image'));
    }

    public function testCorruptImageDataIsSkipped(): void
    {
        $this->requireGd();
        // A valid header for a small image, followed by nothing GD can decode.
        $header = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NNCCCCC', 10, 10, 8, 2, 0, 0, 0) . pack('N', 0);

        $this->assertNull((new ImageRecompressor())->recompress($header, 5, 5, 80, false));
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     *
     * @return string A noisy image, written uncompressed so that recompressing shrinks it.
     */
    private function png(int $width, int $height, bool $palette, bool $alpha, int $transparentPixels = 0): string
    {
        mt_srand(3);
        $image = $palette ? imagecreate($width, $height) : imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        if ($alpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $colours = [];
        for ($i = 0; $i < 8; $i++) {
            $colours[] = (int) imagecolorallocate($image, $i * 30, 255 - $i * 30, ($i * 77) % 256);
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($image, $x, $y, $colours[mt_rand(0, 7)]);
            }
        }

        if ($palette) {
            $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagecolortransparent($image, (int) $clear);
            imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) $clear);
        }

        for ($i = 0; $i < $transparentPixels; $i++) {
            imagesetpixel($image, $i, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 1));
        }

        ob_start();
        imagepng($image, null, 0);

        return (string) ob_get_clean();
    }

    public function testScaledIndexedPngsStayIndexed(): void
    {
        $this->requireGd();
        $png = $this->png(300, 200, true, true);

        $result = (new ImageRecompressor())->recompress($png, 150, 150, 80, false);

        $this->assertNotNull($result);
        $this->assertSame('image/png', $result['mediaType']);
        $image = imagecreatefromstring($result['data']);
        $this->assertNotFalse($image);
        $this->assertFalse(imageistruecolor($image));
    }

    public function testOpaquePngWithAnAlphaChannelIsConvertedButOneWithATransparentPixelIsNot(): void
    {
        $this->requireGd();
        $recompressor = new ImageRecompressor();

        $opaque = $recompressor->recompress($this->png(120, 120, false, true), null, null, 70, true);
        $holey = $recompressor->recompress($this->png(240, 240, false, true, 3), null, null, 70, true);

        $this->assertSame('image/jpeg', $opaque['mediaType'] ?? null);
        $this->assertNotSame('image/jpeg', $holey['mediaType'] ?? null);
    }

    public function testHugeAlphaPngsAreNotScannedForTransparency(): void
    {
        $this->requireGd();
        $image = imagecreatetruecolor(2100, 2000);
        $this->assertNotFalse($image);
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 0);
        $png = (string) ob_get_clean();
        unset($image);

        $result = (new ImageRecompressor())->recompress($png, null, null, 70, true);

        $this->assertNotSame('image/jpeg', $result['mediaType'] ?? null);
    }

    public function testJpegsWithTruncatedExifDataAreStillRecompressed(): void
    {
        $this->requireGd();
        $image = imagecreatetruecolor(200, 200);
        $this->assertNotFalse($image);
        mt_srand(5);
        for ($i = 0; $i < 3000; $i++) {
            imagesetpixel($image, mt_rand(0, 199), mt_rand(0, 199), mt_rand(0, 0xFFFFFF));
        }
        ob_start();
        imagejpeg($image, null, 100);
        $jpeg = (string) ob_get_clean();
        $recompressor = new ImageRecompressor();

        $segments = [
            "Exif\0\0II*\0" . pack('Vv', 8, 1) . pack('vvVV', 0x0100, 3, 1, 5) . pack('V', 0),
            "Exif\0\0II*\0",
            "Exif\0\0II*\0\x00\x00\x00\x00\0\0\0\0",
        ];
        foreach ($segments as $payload) {
            $withExif = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);

            $this->assertNotNull($recompressor->recompress($withExif, null, null, 30, false));
        }
    }
}
