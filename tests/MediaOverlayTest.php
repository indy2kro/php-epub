<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaOverlayTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'overlays';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testAttachesAMediaOverlayToAContentDocument(): void
    {
        $epubFile = $this->open($this->bookWithOverlay());
        $manifest = $epubFile->getManifest();

        $this->assertNull($manifest->getMediaOverlay('chapter'));
        $this->assertNull($manifest->getMediaOverlay('unknown'));

        $manifest->setMediaOverlay('chapter', 'smil-1');
        $this->assertSame('smil-1', $manifest->getMediaOverlay('chapter'));
        $epubFile->save($this->tmpDir . '/out.epub');

        $reopened = EpubFile::open($this->tmpDir . '/out.epub');
        $this->assertSame('smil-1', $reopened->getManifest()->getMediaOverlay('chapter'));
        $this->assertSame([], array_map(strval(...), $reopened->validate()));

        $reopened->getManifest()->setMediaOverlay('chapter', null);
        $this->assertNull($reopened->getManifest()->getMediaOverlay('chapter'));
    }

    public function testDeletingTheOverlayClearsTheReferenceAndItsDuration(): void
    {
        $epubFile = $this->open($this->bookWithOverlay());
        $epubFile->getManifest()->setMediaOverlay('chapter', 'smil-1');
        $epubFile->getMetadata()->setMediaDurationOf('smil-1', '0:01:00');

        $epubFile->getContentManager()->deleteContent('EPUB/audio/ch1.smil');

        $this->assertNull($epubFile->getManifest()->getMediaOverlay('chapter'));
        $this->assertSame([], $epubFile->getMetadata()->getMediaDurations());
    }

    /**
     * @param \Closure(\PhpEpub\Manifest): void $call
     */
    #[DataProvider('invalidOverlayCalls')]
    public function testRejectsAnInvalidMediaOverlay(\Closure $call, string $message): void
    {
        $manifest = $this->open($this->bookWithOverlay())->getManifest();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);
        $call($manifest);
    }

    /**
     * @return iterable<string, array{\Closure(\PhpEpub\Manifest): void, string}>
     */
    public static function invalidOverlayCalls(): iterable
    {
        yield 'unknown content item' => [static fn (\PhpEpub\Manifest $manifest) => $manifest->setMediaOverlay('nope', 'smil-1'), 'No manifest item'];
        yield 'unknown overlay' => [static fn (\PhpEpub\Manifest $manifest) => $manifest->setMediaOverlay('chapter', 'nope'), 'No manifest item'];
        yield 'content that is not a document' => [static fn (\PhpEpub\Manifest $manifest) => $manifest->setMediaOverlay('style', 'smil-1'), 'Only XHTML and SVG'];
        yield 'overlay that is not SMIL' => [static fn (\PhpEpub\Manifest $manifest) => $manifest->setMediaOverlay('chapter', 'style'), 'must be application/smil+xml'];
    }

    public function testMediaOverlaysAreEpub3Only(): void
    {
        $manifest = $this->open(EpubBuilder::epub2())->getManifest();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('only in EPUB 3');
        $manifest->setMediaOverlay('chapter', 'chapter');
    }

    public function testSetsTheTotalAndPerOverlayDurations(): void
    {
        $epubFile = $this->open($this->bookWithOverlay());
        $metadata = $epubFile->getMetadata();

        $this->assertNull($metadata->getMediaDuration());
        $this->assertNull($metadata->getMediaDurationOf('smil-1'));
        $this->assertSame([], $metadata->getMediaDurations());

        $metadata->setMediaDuration('0:32:29');
        $metadata->setMediaDurationOf('smil-1', '0:16:00.5');
        $metadata->setMediaDurationOf('smil-2', '1949s');
        $metadata->setMediaDurationOf('smil-1', '16:00');
        $epubFile->save($this->tmpDir . '/out.epub');

        $reopened = EpubFile::open($this->tmpDir . '/out.epub');
        $metadata = $reopened->getMetadata();
        $this->assertSame('0:32:29', $metadata->getMediaDuration());
        $this->assertSame('16:00', $metadata->getMediaDurationOf('smil-1'));
        $this->assertSame(['smil-1' => '16:00', 'smil-2' => '1949s'], $metadata->getMediaDurations());
        $this->assertSame('0:32:29', $metadata->getProperty('media:duration'), 'The total is the book-level property, not a refinement.');

        $metadata->setMediaDurationOf('smil-1', null);
        $metadata->setMediaDuration(null);
        $this->assertSame(['smil-2' => '1949s'], $metadata->getMediaDurations());
        $this->assertNull($metadata->getMediaDuration());
        $this->assertSame('1949s', $metadata->getMediaDurationOf('smil-2'));
    }

    #[DataProvider('clockValues')]
    public function testValidatesSmilClockValues(string $value, bool $valid): void
    {
        $metadata = $this->open($this->bookWithOverlay())->getMetadata();

        if (! $valid) {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Not a SMIL clock value');
        }

        $metadata->setMediaDuration($value);
        $this->assertSame($value, $metadata->getMediaDuration());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function clockValues(): iterable
    {
        foreach (['0:32:29', '10:00:00.250', '32:29', '00:05.5', '1949', '1949.5s', '3h', '45min', '1500ms', '0.5h'] as $value) {
            yield 'valid ' . $value => [$value, true];
        }

        foreach (['', 'abc', '1:2:3', '0:60:00', '12:61', '1:00:00:00', '-5s', '5 s', '5m', '1.s', ':30', '0:32:29 '] as $value) {
            yield 'invalid "' . $value . '"' => [$value, false];
        }
    }

    public function testRejectsADurationOfSomethingThatIsNotAnOverlay(): void
    {
        $metadata = $this->open($this->bookWithOverlay())->getMetadata();

        foreach (['chapter', 'nope'] as $id) {
            try {
                $metadata->setMediaDurationOf($id, '0:01:00');
                $this->fail('A duration was set for something that is not an overlay.');
            } catch (Exception $exception) {
                $this->assertStringContainsString('is not a media overlay', $exception->getMessage());
            }
        }

        $this->assertFalse($metadata->isModified());
    }

    public function testSetsThePlaybackClassesAndNarrators(): void
    {
        $epubFile = $this->open($this->bookWithOverlay());
        $metadata = $epubFile->getMetadata();

        $this->assertNull($metadata->getMediaActiveClass());
        $this->assertNull($metadata->getMediaPlaybackActiveClass());
        $this->assertSame([], $metadata->getMediaNarrators());

        $metadata->setMediaActiveClass('-epub-media-overlay-active');
        $metadata->setMediaPlaybackActiveClass('-epub-media-overlay-playing');
        $metadata->setMediaNarrators(['Ann Narrator', 'Ben Voice']);
        $epubFile->save($this->tmpDir . '/out.epub');

        $reopened = EpubFile::open($this->tmpDir . '/out.epub');
        $this->assertSame('-epub-media-overlay-active', $reopened->getMetadata()->getMediaActiveClass());
        $this->assertSame('-epub-media-overlay-playing', $reopened->getMetadata()->getMediaPlaybackActiveClass());
        $this->assertSame(['Ann Narrator', 'Ben Voice'], $reopened->getMetadata()->getMediaNarrators());
        $this->assertSame([], array_map(strval(...), $reopened->validate()));

        $reopened->getMetadata()->setMediaActiveClass(null);
        $reopened->getMetadata()->setMediaNarrators([]);
        $this->assertNull($reopened->getMetadata()->getMediaActiveClass());
        $this->assertSame([], $reopened->getMetadata()->getMediaNarrators());
    }

    /**
     * @param \Closure(\PhpEpub\Metadata): void $call
     */
    #[DataProvider('invalidMetadataCalls')]
    public function testRejectsInvalidOverlayMetadata(\Closure $call): void
    {
        $metadata = $this->open($this->bookWithOverlay())->getMetadata();

        $this->expectException(Exception::class);
        $call($metadata);
    }

    /**
     * @return iterable<string, array{\Closure(\PhpEpub\Metadata): void}>
     */
    public static function invalidMetadataCalls(): iterable
    {
        yield 'class with white space' => [static fn (\PhpEpub\Metadata $metadata) => $metadata->setMediaActiveClass('two words')];
        yield 'empty class' => [static fn (\PhpEpub\Metadata $metadata) => $metadata->setMediaPlaybackActiveClass('')];
        yield 'class that is not XML text' => [static fn (\PhpEpub\Metadata $metadata) => $metadata->setMediaActiveClass("a\x01")];
        yield 'empty narrator' => [static fn (\PhpEpub\Metadata $metadata) => $metadata->setMediaNarrators(['Ann', ' '])];
    }

    public function testOverlayMetadataIsEpub3Only(): void
    {
        $metadata = $this->open(EpubBuilder::epub2())->getMetadata();

        $this->assertNull($metadata->getMediaDuration());
        $this->assertSame([], $metadata->getMediaNarrators());
        foreach ([
            static fn () => $metadata->setMediaDuration('0:01:00'),
            static fn () => $metadata->setMediaDurationOf('chapter', '0:01:00'),
            static fn () => $metadata->setMediaActiveClass('active'),
            static fn () => $metadata->setMediaNarrators(['Ann']),
        ] as $call) {
            try {
                $call();
                $this->fail('EPUB 2 packages have no media overlay metadata.');
            } catch (Exception $exception) {
                $this->assertStringContainsString('only in EPUB 3', $exception->getMessage());
            }
        }
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }

    private function bookWithOverlay(): EpubBuilder
    {
        $opf = str_replace(
            '<item id="style"',
            '<item id="smil-1" href="audio/ch1.smil" media-type="application/smil+xml"/><item id="smil-2" href="audio/ch2.smil" media-type="application/smil+xml"/><item id="style"',
            (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
        );
        $smil = '<?xml version="1.0" encoding="UTF-8"?><smil xmlns="http://www.w3.org/ns/SMIL" version="3.0"><body/></smil>';

        return EpubBuilder::epub3()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/audio/ch1.smil', $smil)
            ->withFile('EPUB/audio/ch2.smil', $smil);
    }
}
