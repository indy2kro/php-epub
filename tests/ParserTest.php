<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\InvalidEpubException;
use PhpEpub\Parser;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\XmlParser;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    private Parser $parser;
    private string $fixturesDir;
    private string $tmpDir;
    private FileSystemHelper $fileSystemHelper;

    protected function setUp(): void
    {
        $this->fileSystemHelper = new FileSystemHelper();
        $this->fixturesDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures';
        $this->tmpDir = $this->fixturesDir . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'temp_epub';

        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }

        $this->parser = new Parser(new XmlParser());
    }

    protected function tearDown(): void
    {
        $this->fileSystemHelper->deleteDirectory($this->tmpDir);
    }

    public function testParseValidEpub(): void
    {
        $directory = $this->copyFixtureToTmp('valid_epub');

        $opfPath = $this->parser->parse($directory);

        $this->assertSame('EPUB/package.opf', $opfPath);
    }

    public function testParseMissingMimetypeThrowsException(): void
    {
        $directory = $this->copyFixtureToTmp('valid_epub');
        unlink($directory . '/mimetype');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing mimetype file:');

        $this->parser->parse($directory);
    }

    public function testParseInvalidMimetypeContentThrowsException(): void
    {
        $directory = $this->copyFixtureToTmp('valid_epub');
        file_put_contents($directory . '/mimetype', 'invalid-mimetype');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid mimetype content:');

        $this->parser->parse($directory);
    }

    public function testExtractOpfPathMissingRootfileThrowsException(): void
    {
        $directory = $this->copyFixtureToTmp('valid_epub');
        unlink($directory . '/META-INF/container.xml');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('XML file not found:');

        $this->parser->parse($directory);
    }

    public function testValidateOpfMissingManifestThrowsException(): void
    {
        $directory = $this->copyFixtureToTmp('valid_epub');
        $opfPath = $directory . '/EPUB/package.opf';
        $opfContent = file_get_contents($opfPath);
        $this->assertNotFalse($opfContent);
        file_put_contents($opfPath, str_replace('<manifest>', '', $opfContent));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to load XML file:');

        $this->parser->parse($directory);
    }

    public function testParseRejectsOpfPathOutsideTheBook(): void
    {
        // A readable OPF exists outside the book, so only path confinement can stop this.
        file_put_contents($this->tmpDir . '/outside.opf', EpubBuilder::opf());
        $directory = EpubBuilder::minimal()
            ->withContainer('../outside.opf')
            ->writeTo($this->tmpDir . '/book');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('outside the EPUB');

        $this->parser->parse($directory);
    }

    public function testParseRejectsAbsoluteOpfPath(): void
    {
        $directory = EpubBuilder::minimal()
            ->withContainer('/etc/package.opf')
            ->writeTo($this->tmpDir . '/book');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('outside the EPUB');

        $this->parser->parse($directory);
    }

    public function testParseRejectsNcxPathOutsideTheBook(): void
    {
        file_put_contents(
            $this->tmpDir . '/outside.ncx',
            '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><navMap/></ncx>'
        );
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', EpubBuilder::opf(
                '<item id="ncx" href="../../outside.ncx" media-type="application/x-dtbncx+xml"/>'
            ))
            ->writeTo($this->tmpDir . '/book');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('outside the EPUB');

        $this->parser->parse($directory);
    }

    public function testParseNormalizesOpfPath(): void
    {
        $directory = EpubBuilder::minimal()
            ->withContainer('./META-INF/../EPUB/package.opf')
            ->writeTo($this->tmpDir . '/book');

        $this->assertSame('EPUB/package.opf', $this->parser->parse($directory));
    }

    public function testParseEmptyRootfilesThrowsException(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('META-INF/container.xml', '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0"><rootfiles/></container>')
            ->writeTo($this->tmpDir . '/book');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No rootfile found in container.xml');

        $this->parser->parse($directory);
    }

    public function testParseOpfWithoutManifestThrowsException(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', '<package xmlns="http://www.idpf.org/2007/opf" version="3.0"><metadata/><spine/></package>')
            ->writeTo($this->tmpDir . '/book');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing manifest in OPF file');

        $this->parser->parse($directory);
    }

    public function testParseContainerWithoutNamespaceThrowsException(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('META-INF/container.xml', '<container version="1.0"><rootfiles><rootfile full-path="EPUB/package.opf"/></rootfiles></container>')
            ->writeTo($this->tmpDir . '/book');

        $this->expectException(InvalidEpubException::class);
        $this->expectExceptionMessage('No container namespace found in container.xml');

        $this->parser->parse($directory);
    }

    public function testParseRootfileWithoutFullPathThrowsException(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('META-INF/container.xml', '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0"><rootfiles><rootfile/></rootfiles></container>')
            ->writeTo($this->tmpDir . '/book');

        $this->expectException(InvalidEpubException::class);
        $this->expectExceptionMessage('Missing full-path attribute in rootfile element');

        $this->parser->parse($directory);
    }

    public function testParseOpfWithoutNamespaceThrowsException(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', '<package version="3.0"><metadata/><manifest/><spine/></package>')
            ->writeTo($this->tmpDir . '/book');

        $this->expectException(InvalidEpubException::class);
        $this->expectExceptionMessage('No OPF namespace found in OPF file');

        $this->parser->parse($directory);
    }

    public function testParseNcxWithoutNavMapThrowsException(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', EpubBuilder::opf(
                '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
            ))
            ->withFile('EPUB/toc.ncx', '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"/>')
            ->writeTo($this->tmpDir . '/book');

        $this->expectException(InvalidEpubException::class);
        $this->expectExceptionMessage('Missing navMap in NCX file');

        $this->parser->parse($directory);
    }

    public function testParseNcxWithoutNamespaceThrowsException(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', EpubBuilder::opf(
                '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
            ))
            ->withFile('EPUB/toc.ncx', '<ncx version="2005-1"><navMap/></ncx>')
            ->writeTo($this->tmpDir . '/book');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No NCX namespace found');

        $this->parser->parse($directory);
    }

    public function testPrefixedContainerPackageAndNcxLoad(): void
    {
        $opf = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<opf:package xmlns:opf="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/" version="2.0" unique-identifier="uid">'
            . '<opf:metadata><dc:identifier id="uid">urn:x</dc:identifier><dc:title>Prefixed</dc:title><dc:language>en</dc:language></opf:metadata>'
            . '<opf:manifest><opf:item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/>'
            . '<opf:item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/></opf:manifest>'
            . '<opf:spine toc="ncx"><opf:itemref idref="chapter"/></opf:spine></opf:package>';
        $epubPath = EpubBuilder::minimal()
            ->withFile('META-INF/container.xml', '<c:container xmlns:c="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">'
                . '<c:rootfiles><c:rootfile full-path="EPUB/package.opf" media-type="application/oebps-package+xml"/></c:rootfiles></c:container>')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/toc.ncx', '<n:ncx xmlns:n="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><n:navMap/></n:ncx>')
            ->buildEpub($this->tmpDir . '/prefixed.epub');

        $epubFile = EpubFile::open($epubPath);

        $this->assertSame('Prefixed', $epubFile->getMetadata()->getTitle());
        $this->assertSame(['chapter'], $epubFile->getSpine()->get());
        $epubFile->cleanup();
    }

    public function testContainerAndNcxWithAnUnexpectedDefaultNamespaceStillLoad(): void
    {
        // Books in the wild get namespace URIs slightly wrong; these loaded before and must keep loading.
        $directory = EpubBuilder::minimal()
            ->withFile('META-INF/container.xml', '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container:typo" version="1.0">'
                . '<rootfiles><rootfile full-path="EPUB/package.opf"/></rootfiles></container>')
            ->withFile('EPUB/package.opf', EpubBuilder::opf('<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'))
            ->withFile('EPUB/toc.ncx', '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx" version="2005-1"><navMap/></ncx>')
            ->writeTo($this->tmpDir . '/book');

        $this->assertSame('EPUB/package.opf', $this->parser->parse($directory));
    }

    public function testParsePicksThePackageRootfileWhenItIsNotFirst(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('META-INF/container.xml', '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0"><rootfiles>'
                . '<rootfile full-path="book.pdf" media-type="application/pdf"/>'
                . '<rootfile full-path="EPUB/package.opf" media-type="application/oebps-package+xml"/>'
                . '</rootfiles></container>')
            ->writeTo($this->tmpDir . '/book');

        $this->assertSame('EPUB/package.opf', $this->parser->parse($directory));
    }

    private function copyFixtureToTmp(string $fixtureName): string
    {
        $sourceDir = $this->fixturesDir . '/' . $fixtureName;
        $destDir = $this->tmpDir . '/' . $fixtureName;

        $this->copyDirectory($sourceDir, $destDir);

        return $destDir;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0777, true);

        $items = scandir($source);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $srcPath = $source . '/' . $item;
            $destPath = $destination . '/' . $item;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $destPath);
            } else {
                copy($srcPath, $destPath);
            }
        }
    }
}
