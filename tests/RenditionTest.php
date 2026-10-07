<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RenditionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'rendition';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testBooksHaveNoRenditionUntilItIsSet(): void
    {
        $metadata = $this->open(EpubBuilder::epub3())->getMetadata();

        $this->assertNull($metadata->getRenditionLayout());
        $this->assertNull($metadata->getRenditionOrientation());
        $this->assertNull($metadata->getRenditionSpread());
        $this->assertNull($metadata->getRenditionFlow());
    }

    public function testSetsAndReadsTheRenditionMetadata(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $metadata = $epubFile->getMetadata();

        $metadata->setRenditionLayout('pre-paginated');
        $metadata->setRenditionOrientation('portrait');
        $metadata->setRenditionSpread('none');
        $metadata->setRenditionFlow('paginated');
        $epubFile->save($this->tmpDir . '/out.epub');

        $reopened = EpubFile::open($this->tmpDir . '/out.epub');
        $this->assertSame('pre-paginated', $reopened->getMetadata()->getRenditionLayout());
        $this->assertSame('portrait', $reopened->getMetadata()->getRenditionOrientation());
        $this->assertSame('none', $reopened->getMetadata()->getRenditionSpread());
        $this->assertSame('paginated', $reopened->getMetadata()->getRenditionFlow());
        $this->assertSame([], array_map(strval(...), $reopened->validate()));

        $opf = $reopened->getContentManager()->getContent('EPUB/package.opf');
        $this->assertStringContainsString('prefix="rendition: http://www.idpf.org/vocab/rendition/#"', $opf);
        $this->assertSame(1, substr_count($opf, 'rendition: http'), 'The prefix is declared once.');
        $this->assertStringContainsString('<meta property="rendition:layout">pre-paginated</meta>', $opf);

        $metadata = $reopened->getMetadata();
        $metadata->setRenditionLayout('reflowable');
        $this->assertSame('reflowable', $metadata->getRenditionLayout());
        $metadata->setRenditionLayout(null);
        $this->assertNull($metadata->getRenditionLayout());
    }

    public function testKeepsAnExistingPrefixDeclaration(): void
    {
        $opf = str_replace('version="3.0"', 'version="3.0" prefix="foaf: http://xmlns.com/foaf/spec/"', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf));

        $epubFile->getMetadata()->setRenditionFlow('scrolled-doc');
        $epubFile->getMetadata()->setRenditionSpread('both');
        $epubFile->save($this->tmpDir . '/out.epub');

        $opf = $this->opf();
        $this->assertStringContainsString('prefix="foaf: http://xmlns.com/foaf/spec/ rendition: http://www.idpf.org/vocab/rendition/#"', $opf);
    }

    public function testDoesNotRedeclareAPrefixThePackageHas(): void
    {
        $opf = str_replace('version="3.0"', 'version="3.0" prefix="rendition: http://www.idpf.org/vocab/rendition/#"', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf));

        $epubFile->getMetadata()->setRenditionOrientation('auto');
        $epubFile->save($this->tmpDir . '/out.epub');

        $opf = $this->opf();
        $this->assertSame(1, substr_count($opf, 'rendition: http'));
    }

    #[DataProvider('invalidRenditions')]
    public function testRejectsAValueTheVocabularyDoesNotAllow(string $setter, string $value): void
    {
        $metadata = $this->open(EpubBuilder::epub3())->getMetadata();

        try {
            $metadata->{$setter}($value);
            $this->fail('An invalid value was accepted.');
        } catch (Exception $exception) {
            $this->assertStringContainsString($value, $exception->getMessage());
        }

        $this->assertFalse($metadata->isModified());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRenditions(): iterable
    {
        yield 'layout' => ['setRenditionLayout', 'fixed'];
        yield 'orientation' => ['setRenditionOrientation', 'sideways'];
        yield 'spread' => ['setRenditionSpread', 'always'];
        yield 'flow' => ['setRenditionFlow', 'scrolled'];
    }

    public function testRenditionMetadataIsEpub3Only(): void
    {
        $metadata = $this->open(EpubBuilder::epub2())->getMetadata();

        $this->assertNull($metadata->getRenditionLayout());
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('only in EPUB 3');
        $metadata->setRenditionLayout('pre-paginated');
    }

    public function testSetsThePropertiesOfASpineItem(): void
    {
        $epubFile = $this->open($this->bookWithTwoPages());
        $spine = $epubFile->getSpine();

        $this->assertSame([], $spine->getItemProperties('one'));
        $this->assertSame(['page-spread-right'], $spine->getItemProperties('two'));

        $spine->setItemProperties('one', ['page-spread-left', 'rendition:layout-pre-paginated', 'page-spread-left']);
        $spine->setItemProperties('two', []);
        $epubFile->save($this->tmpDir . '/out.epub');

        $reopened = EpubFile::open($this->tmpDir . '/out.epub');
        $this->assertSame(['page-spread-left', 'rendition:layout-pre-paginated'], $reopened->getSpine()->getItemProperties('one'));
        $this->assertSame([], $reopened->getSpine()->getItemProperties('two'));
        $this->assertSame(['one', 'two'], $reopened->getSpine()->get());
        $this->assertStringContainsString('rendition: http://www.idpf.org/vocab/rendition/#', $reopened->getContentManager()->getContent('EPUB/package.opf'));
    }

    public function testDoesNotDeclareTheRenditionPrefixForOtherProperties(): void
    {
        $epubFile = $this->open($this->bookWithTwoPages());

        $epubFile->getSpine()->setItemProperties('one', ['page-spread-left']);

        $epubFile->save($this->tmpDir . '/out.epub');
        $this->assertStringNotContainsString('prefix=', $this->opf());
    }

    public function testSetsThePageSpread(): void
    {
        $epubFile = $this->open($this->bookWithTwoPages());
        $spine = $epubFile->getSpine();

        $this->assertNull($spine->getPageSpread('one'));
        $this->assertSame('right', $spine->getPageSpread('two'));

        $spine->setItemProperties('one', ['rendition:layout-pre-paginated']);
        $spine->setPageSpread('one', 'center');
        $this->assertSame('center', $spine->getPageSpread('one'));
        $this->assertSame(['rendition:layout-pre-paginated', 'rendition:page-spread-center'], $spine->getItemProperties('one'));

        $spine->setPageSpread('two', 'left');
        $this->assertSame(['page-spread-left'], $spine->getItemProperties('two'));

        $spine->setPageSpread('two', null);
        $this->assertNull($spine->getPageSpread('two'));
        $this->assertSame([], $spine->getItemProperties('two'));
    }

    public function testSetsThePerItemRenditionOverrides(): void
    {
        $epubFile = $this->open($this->bookWithTwoPages());
        $spine = $epubFile->getSpine();

        $this->assertNull($spine->getItemRendition('one', 'layout'));

        $spine->setItemRendition('two', 'layout', 'reflowable');
        $spine->setItemRendition('two', 'orientation', 'landscape');
        $spine->setItemRendition('two', 'layout', 'pre-paginated');

        $this->assertSame('pre-paginated', $spine->getItemRendition('two', 'layout'));
        $this->assertSame('landscape', $spine->getItemRendition('two', 'orientation'));
        $this->assertNull($spine->getItemRendition('two', 'flow'));
        $this->assertSame(['page-spread-right', 'rendition:orientation-landscape', 'rendition:layout-pre-paginated'], $spine->getItemProperties('two'));

        $spine->setItemRendition('two', 'orientation', null);
        $this->assertNull($spine->getItemRendition('two', 'orientation'));
        $this->assertSame(['page-spread-right', 'rendition:layout-pre-paginated'], $spine->getItemProperties('two'));
    }

    /**
     * @param \Closure(\PhpEpub\Spine): void $call
     */
    #[DataProvider('invalidSpineCalls')]
    public function testRejectsInvalidSpineProperties(\Closure $call): void
    {
        $spine = $this->open($this->bookWithTwoPages())->getSpine();

        $this->expectException(Exception::class);
        $call($spine);
    }

    /**
     * @return iterable<string, array{\Closure(\PhpEpub\Spine): void}>
     */
    public static function invalidSpineCalls(): iterable
    {
        yield 'unknown item' => [static fn (\PhpEpub\Spine $spine) => $spine->getItemProperties('nope')];
        yield 'unknown item when setting' => [static fn (\PhpEpub\Spine $spine) => $spine->setItemProperties('nope', ['x'])];
        yield 'empty property' => [static fn (\PhpEpub\Spine $spine) => $spine->setItemProperties('one', [''])];
        yield 'property with white space' => [static fn (\PhpEpub\Spine $spine) => $spine->setItemProperties('one', ['a b'])];
        yield 'invalid XML text' => [static fn (\PhpEpub\Spine $spine) => $spine->setItemProperties('one', ["a\x01"])];
        yield 'bad side' => [static fn (\PhpEpub\Spine $spine) => $spine->setPageSpread('one', 'top')];
        yield 'bad aspect when reading' => [static fn (\PhpEpub\Spine $spine) => $spine->getItemRendition('one', 'size')];
        yield 'bad aspect when setting' => [static fn (\PhpEpub\Spine $spine) => $spine->setItemRendition('one', 'size', 'big')];
        yield 'bad value' => [static fn (\PhpEpub\Spine $spine) => $spine->setItemRendition('one', 'layout', 'fixed')];
    }

    public function testSpineItemPropertiesAreEpub3Only(): void
    {
        $spine = $this->open(EpubBuilder::epub2())->getSpine();

        $this->assertSame([], $spine->getItemProperties('chapter'));
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('exist only in EPUB 3');
        $spine->setItemProperties('chapter', ['page-spread-left']);
    }

    /**
     * The package document of the book the test saved to out.epub.
     */
    private function opf(): string
    {
        $epubFile = EpubFile::open($this->tmpDir . '/out.epub');

        return $epubFile->getContentManager()->getContent('EPUB/package.opf');
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }

    private function bookWithTwoPages(): EpubBuilder
    {
        $opf = str_replace(
            ['<item id="chapter"', '<itemref idref="chapter"/>', '<item id="style"'],
            ['<item id="one"', '<itemref idref="one"/><itemref idref="two" properties="page-spread-right"/>', '<item id="two" href="text/two.xhtml" media-type="application/xhtml+xml"/><item id="style"'],
            (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
        );

        return EpubBuilder::epub3()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/text/two.xhtml', EpubBuilder::xhtml('Two', '<p>Two</p>'));
    }
}
