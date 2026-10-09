<?php

declare(strict_types=1);

namespace PhpEpub\Test\Cleanup;

use PhpEpub\Cleanup\Cleanup;
use PhpEpub\Cleanup\CleanupAction;
use PhpEpub\Cleanup\CleanupOptions;
use PhpEpub\Cleanup\CleanupPreset;
use PhpEpub\Cleanup\ImageRecompressor;
use PhpEpub\EpubFile;
use PhpEpub\ManifestItem;
use PhpEpub\Test\Support\CleanupBook;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * Image recompression needs the GD extension (CI has it); the tests are skipped without it.
 */
final class ImageCleanupTest extends TestCase
{
    private string $tmpDir;

    private ?EpubFile $book = null;

    protected function setUp(): void
    {
        if (! ImageRecompressor::isAvailable()) {
            $this->markTestSkipped('The GD extension is not available.');
        }

        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'images';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->book?->cleanup();
        if (isset($this->tmpDir)) {
            (new FileSystemHelper())->deleteDirectory($this->tmpDir);
        }
    }

    /**
     * A photo-like image: smooth gradients with noise, which JPEG shrinks and PNG cannot.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     *
     * @return string PNG or JPEG bytes.
     */
    private function image(int $width, int $height, string $format = 'png', bool $alpha = false): string
    {
        mt_srand(42);
        $channel = static fn (int $value): int => max(0, min(255, $value));
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        if ($alpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $noise = mt_rand(0, 24);
                $colour = $alpha && $x < $width / 2
                    ? imagecolorallocatealpha($image, 10, 20, 30, 100)
                    : imagecolorallocate($image, $channel(intdiv($x * 230, $width) + $noise), $channel(intdiv($y * 230, $height) + $noise), $channel(intdiv(($x + $y) * 230, $width + $height) + $noise));
                imagesetpixel($image, $x, $y, (int) $colour);
            }
        }

        ob_start();
        $format === 'png' ? imagepng($image, null, 0) : imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }

    /**
     * @return array{int, int}
     */
    private function dimensions(string $data): array
    {
        $info = getimagesizefromstring($data);
        $this->assertNotFalse($info);

        return [$info[0], $info[1]];
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return $this->book = CleanupBook::open($builder, $this->tmpDir);
    }

    private function read(string $path): string
    {
        return $this->book?->getContentManager()->getContent($path) ?? '';
    }

    public function testLargeImagesAreScaledDownAndRecompressedWithoutUpscaling(): void
    {
        $big = $this->image(1000, 700, 'jpeg');
        $small = $this->image(60, 40, 'jpeg');
        $builder = CleanupBook::builder(
            '<item id="big" href="big.jpg" media-type="image/jpeg"/><item id="small" href="small.jpg" media-type="image/jpeg"/>',
            ['EPUB/big.jpg' => $big, 'EPUB/small.jpg' => $small],
            chapterBody: '<img src="big.jpg" alt=""/><img src="small.jpg" alt=""/>'
        );
        $book = $this->open($builder);

        $report = (new Cleanup($book))->run(new CleanupOptions(recompressImages: true, maxImageWidth: 500, maxImageHeight: 500, jpegQuality: 60));

        $action = $report->getAction(CleanupAction::IMAGES);
        $this->assertInstanceOf(\PhpEpub\Cleanup\CleanupAction::class, $action);
        $this->assertFalse($action->skipped);
        $this->assertSame([500, 350], $this->dimensions($this->read('EPUB/big.jpg')));
        $this->assertLessThan(strlen($big), strlen($this->read('EPUB/big.jpg')));
        $this->assertContains('EPUB/big.jpg', $action->files);
        $this->assertGreaterThan(0, $action->bytesSaved());
        // The small image keeps its size (re-encoding it is only kept when smaller).
        $this->assertSame([60, 40], $this->dimensions($this->read('EPUB/small.jpg')));
        $this->assertLessThanOrEqual(strlen($small), strlen($this->read('EPUB/small.jpg')));
    }

    public function testImagesThatDoNotShrinkAreKeptAsTheyAre(): void
    {
        $tiny = (string) base64_decode(EpubBuilder::JPEG, true);
        $builder = CleanupBook::builder('<item id="t" href="t.jpg" media-type="image/jpeg"/>', ['EPUB/t.jpg' => $tiny], chapterBody: '<img src="t.jpg" alt=""/>');
        $book = $this->open($builder);

        $report = (new Cleanup($book))->run(new CleanupOptions(recompressImages: true, jpegQuality: 50));

        $this->assertSame([], $report->getAction(CleanupAction::IMAGES)?->files);
        $this->assertSame($tiny, $this->read('EPUB/t.jpg'));
    }

    public function testTransparentPngStaysPngAndKeepsItsAlpha(): void
    {
        $png = $this->image(300, 200, 'png', true);
        $builder = CleanupBook::builder('<item id="p" href="p.png" media-type="image/png"/>', ['EPUB/p.png' => $png], chapterBody: '<img src="p.png" alt=""/>');
        $book = $this->open($builder);

        (new Cleanup($book))->run(new CleanupOptions(recompressImages: true, maxImageWidth: 150, convertOpaquePngToJpeg: true));

        $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $book->getManifest()->findByPath('EPUB/p.png'));
        $data = $this->read('EPUB/p.png');
        $this->assertSame([150, 100], $this->dimensions($data));
        $image = imagecreatefromstring($data);
        $this->assertNotFalse($image);
        $this->assertGreaterThan(0, (imagecolorat($image, 5, 5) >> 24) & 0x7F, 'The transparent half lost its alpha.');
        $this->assertSame(0, (imagecolorat($image, 140, 50) >> 24) & 0x7F);
    }

    public function testOpaquePngIsConvertedToJpegWithEveryReferenceUpdated(): void
    {
        $png = $this->image(400, 300, 'png');
        $builder = CleanupBook::builder(
            '<item id="photo" href="img/photo.png" media-type="image/png"/><item id="css" href="s.css" media-type="text/css"/>',
            ['EPUB/img/photo.png' => $png, 'EPUB/s.css' => 'body { background: url("img/photo.png") }'],
            chapterBody: '<img src="img/photo.png" srcset="img/photo.png 1x, img/photo.png 2x" alt=""/><a href="img/photo.png#x">big</a>'
        )->withFile('EPUB/chapter.xhtml', EpubBuilder::xhtml('Chapter', '<img src="img/photo.png" srcset="img/photo.png 1x, img/photo.png 2x" alt=""/><a href="img/photo.png#x">big</a>', 's.css'));
        $book = $this->open($builder);

        $report = (new Cleanup($book))->run(CleanupOptions::preset(CleanupPreset::Strong));

        $this->assertSame(['EPUB/img/photo.jpg'], $report->getAction(CleanupAction::IMAGES)?->files);
        $item = $book->getManifest()->get('photo');
        $this->assertInstanceOf(ManifestItem::class, $item);
        $this->assertSame('EPUB/img/photo.jpg', $item->path);
        $this->assertSame('image/jpeg', $item->mediaType);
        $this->assertNotContains('EPUB/img/photo.png', $book->getContentManager()->getContentPaths());
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($this->read('EPUB/img/photo.jpg'))[2] ?? null);
        $chapter = $this->read('EPUB/chapter.xhtml');
        $this->assertStringNotContainsString('photo.png', $chapter);
        $this->assertStringContainsString('src="img/photo.jpg"', $chapter);
        $this->assertStringContainsString('img/photo.jpg 1x, img/photo.jpg 2x', $chapter);
        $this->assertStringContainsString('href="img/photo.jpg#x"', $chapter);
        $this->assertStringContainsString('url("img/photo.jpg")', $this->read('EPUB/s.css'));
        $this->assertSame([], array_map(strval(...), $book->validate()));
    }

    public function testOpaquePngStaysPngWithoutTheConversionOption(): void
    {
        $png = $this->image(300, 200, 'png');
        $builder = CleanupBook::builder('<item id="p" href="p.png" media-type="image/png"/>', ['EPUB/p.png' => $png], chapterBody: '<img src="p.png" alt=""/>');
        $book = $this->open($builder);

        (new Cleanup($book))->run(CleanupOptions::preset(CleanupPreset::Balanced));

        $this->assertSame('image/png', $book->getManifest()->get('p')?->mediaType);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($this->read('EPUB/p.png'))[2] ?? null);
        $this->assertLessThan(strlen($png), strlen($this->read('EPUB/p.png')));
    }

    public function testNoPngIsConvertedWhenADocumentCannotBeRewritten(): void
    {
        $png = $this->image(300, 200, 'png');
        $builder = CleanupBook::builder(
            '<item id="p" href="p.png" media-type="image/png"/><item id="broken" href="broken.xhtml" media-type="application/xhtml+xml"/>',
            ['EPUB/p.png' => $png, 'EPUB/broken.xhtml' => '<html><img src="p.png">'],
            '<itemref idref="broken"/>',
            '<img src="p.png" alt=""/>'
        );
        $book = $this->open($builder);

        (new Cleanup($book))->run(CleanupOptions::preset(CleanupPreset::Strong));

        $this->assertSame('EPUB/p.png', $book->getManifest()->get('p')?->path);
    }

    public function testGifSvgAndAnimatedPngAreNotTouched(): void
    {
        $gif = (string) base64_decode(EpubBuilder::GIF, true);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"><rect width="400" height="400"/></svg>';
        $builder = CleanupBook::builder(
            '<item id="g" href="g.gif" media-type="image/gif"/><item id="s" href="s.svg" media-type="image/svg+xml"/>',
            ['EPUB/g.gif' => $gif, 'EPUB/s.svg' => $svg],
            chapterBody: '<img src="g.gif" alt=""/><img src="s.svg" alt=""/>'
        );
        $book = $this->open($builder);

        $report = (new Cleanup($book))->run(CleanupOptions::preset(CleanupPreset::Strong));

        $this->assertSame([], $report->getAction(CleanupAction::IMAGES)?->files);
        $this->assertSame($gif, $this->read('EPUB/g.gif'));
        $this->assertSame($svg, $this->read('EPUB/s.svg'));

        $apng = substr($this->image(300, 200, 'png'), 0, 33) . "\0\0\0\x08acTL\0\0\0\x01\0\0\0\0\0\0\0\0" . substr($this->image(300, 200, 'png'), 33);
        $this->assertNull((new ImageRecompressor())->recompress($apng, 100, 100, 50, false));
    }

    public function testJpegWithAnExifOrientationAndCorruptDataAreSkipped(): void
    {
        $jpeg = $this->image(400, 300, 'jpeg');
        $tiff = "MM\0\x2A\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01\0\x06\0\0\0\0\0\0";
        $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff;
        $rotated = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);

        $recompressor = new ImageRecompressor();

        $this->assertNull($recompressor->recompress($rotated, 100, 100, 50, false));
        $this->assertNotNull($recompressor->recompress($jpeg, 100, 100, 50, false));
        $this->assertNull($recompressor->recompress('not an image', 100, 100, 50, false));
        $this->assertNull($recompressor->recompress(substr($jpeg, 0, 300), 100, 100, 50, false));
    }
}
