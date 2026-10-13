<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\ConversionException;
use PhpEpub\Converters\ConverterInterface;
use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\Converters\TCPDFAdapter;
use PhpEpub\EpubFile;
use PhpEpub\EpubReader;
use PhpEpub\Exception;
use PhpEpub\Limits;
use PhpEpub\Merge\MergeOptions;
use PhpEpub\Merge\Merger;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Test\Support\MergeBook;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * DRM-protected books (encrypted resources, Adobe ADEPT, Readium LCP) are detected, reported
 * by validate() as one error and never converted; font obfuscation is not DRM.
 */
final class EncryptionTest extends TestCase
{
    private const string AES = 'http://www.w3.org/2001/04/xmlenc#aes128-cbc';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'encryption';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testAnOrdinaryBookIsNotDrmProtected(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());

        $this->assertFalse($epubFile->isDrmProtected());
        $this->assertSame([], $epubFile->getEncryptedPaths());
        $this->assertSame([], $epubFile->validate());
    }

    public function testEncryptedResourcesMakeTheBookDrmProtected(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3()
            ->withFile('META-INF/encryption.xml', self::encryption([
                'EPUB/text/chapter.xhtml' => self::AES,
                'EPUB/css/style.css' => 'http://readium.org/2014/01/lcp#aes256-cbc',
                'EPUB/text/with%20space.xhtml' => self::AES,
                '../outside.xhtml' => self::AES,
            ]))
            ->withFile('EPUB/text/chapter.xhtml', 'ciphertext, not XML'));

        $this->assertTrue($epubFile->isDrmProtected());
        $this->assertSame(
            ['EPUB/css/style.css', 'EPUB/text/chapter.xhtml', 'EPUB/text/with space.xhtml'],
            $epubFile->getEncryptedPaths()
        );
    }

    public function testValidateReportsOneErrorInsteadOfOneForEveryEncryptedDocument(): void
    {
        $builder = EpubBuilder::epub3()
            ->withFile('META-INF/encryption.xml', self::encryption([
                'EPUB/text/chapter.xhtml' => self::AES,
                'EPUB/nav.xhtml' => self::AES,
                'EPUB/images/cover.png' => self::AES,
            ]))
            ->withFile('EPUB/text/chapter.xhtml', 'ciphertext, not XML')
            ->withFile('EPUB/nav.xhtml', "\x01\x02 more ciphertext")
            ->withFile('EPUB/images/cover.png', 'ciphertext, not a PNG')
            ->withFile('EPUB/package.opf', str_replace(
                '</manifest>',
                '<item id="cover" href="images/cover.png" media-type="image/png"/></manifest>',
                (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
            ));

        $issues = $this->open($builder)->validate();

        $this->assertCount(1, $issues);
        $this->assertSame('CONTENT_ENCRYPTED', $issues[0]->code);
        $this->assertSame('error', $issues[0]->severity);
        $this->assertSame('META-INF/encryption.xml', $issues[0]->location);
        $this->assertStringContainsString('3 of its resources', $issues[0]->message);
    }

    public function testAnEncryptedDocumentIsNotCheckedForManifestProperties(): void
    {
        $opf = str_replace('href="text/chapter.xhtml" media-type="application/xhtml+xml"', 'href="text/chapter.xhtml" media-type="application/xhtml+xml" properties="svg"', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $builder = EpubBuilder::epub3()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('META-INF/encryption.xml', self::encryption(['EPUB/text/chapter.xhtml' => self::AES]));

        $codes = array_map(static fn ($issue): string => $issue->code, $this->open($builder)->validate());

        $this->assertSame(['CONTENT_ENCRYPTED'], $codes);
    }

    public function testAReadiumLcpLicenseMakesTheBookDrmProtected(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('META-INF/license.lcpl', '{"id":"x"}'));

        $this->assertTrue($epubFile->isDrmProtected());
        $this->assertSame([], $epubFile->getEncryptedPaths());

        $issues = $epubFile->validate();
        $this->assertCount(1, $issues);
        $this->assertSame('CONTENT_ENCRYPTED', $issues[0]->code);
        $this->assertNull($issues[0]->location);
    }

    public function testAnAdobeAdeptRightsFileMakesTheBookDrmProtected(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('META-INF/rights.xml', '<rights/>'));

        $this->assertTrue($epubFile->isDrmProtected());
    }

    public function testFontObfuscationAloneIsNotDrm(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('META-INF/encryption.xml', self::encryption([
            'EPUB/fonts/a.otf' => 'http://www.idpf.org/2008/embedding',
            'EPUB/fonts/b.otf' => 'http://ns.adobe.com/pdf/enc#RC',
        ])));

        $this->assertFalse($epubFile->isDrmProtected());
        $this->assertSame([], $epubFile->getEncryptedPaths());
    }

    public function testADamagedEncryptionFileDoesNotStopTheBookFromLoadingButCountsAsProtection(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('META-INF/encryption.xml', '<encryption><broken'));

        $this->assertTrue($epubFile->isDrmProtected());
        $this->assertSame([], $epubFile->getEncryptedPaths());
        $this->assertSame('Valid Book', $epubFile->getMetadata()->getTitle());

        $issues = $epubFile->validate();
        $this->assertCount(1, $issues);
        $this->assertSame('CONTENT_ENCRYPTED', $issues[0]->code);
        $this->assertSame('META-INF/encryption.xml', $issues[0]->location);
        $this->assertStringContainsString('cannot be read', $issues[0]->message);
    }

    public function testAnEncryptionFileWithAnEntityDeclarationCountsAsProtection(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3()->withFile(
            'META-INF/encryption.xml',
            '<?xml version="1.0"?><!DOCTYPE encryption [<!ENTITY x "y">]><encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container"/>'
        ));

        $this->assertTrue($epubFile->isDrmProtected());
        $this->expectException(ConversionException::class);
        $epubFile->convert(new TCPDFAdapter(), $this->tmpDir . '/out.pdf');
    }

    public function testAnOversizedEncryptionFileCountsAsProtection(): void
    {
        // Padded to be larger than the package document, which the same cap applies to.
        $xml = str_replace('</encryption>', '<!--' . str_repeat('x', 20000) . '--></encryption>', self::encryption(['EPUB/fonts/a.otf' => 'http://www.idpf.org/2008/embedding']));
        $path = EpubBuilder::epub3()->withFile('META-INF/encryption.xml', $xml)->buildEpub($this->tmpDir . '/capped.epub');

        $this->assertFalse(EpubFile::open($path, limits: new Limits(maxXmlBytes: strlen($xml)))->isDrmProtected());

        $capped = EpubFile::open($path, limits: new Limits(maxXmlBytes: strlen($xml) - 1));
        $this->assertTrue($capped->isDrmProtected());

        $output = $this->tmpDir . '/out.pdf';
        try {
            $capped->convert(new DompdfAdapter(), $output);
            $this->fail('A book whose encryption.xml is too large to read must not be converted');
        } catch (ConversionException $conversionException) {
            $this->assertStringContainsString('DRM-protected', $conversionException->getMessage());
        }

        $this->assertFileDoesNotExist($output);
    }

    public function testAnUnreadableEncryptionFileStopsAMerge(): void
    {
        $books = [
            MergeBook::builder('urn:uuid:11111111-1111-4111-8111-111111111111', 'One', 1)->withFile('META-INF/encryption.xml', '<encryption><broken'),
            MergeBook::builder('urn:uuid:22222222-2222-4222-8222-222222222222', 'Two', 1),
        ];
        $opened = array_map($this->open(...), $books);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('DRM-protected');
        (new Merger())->merge($opened, new MergeOptions(), $this->tmpDir . '/merged.epub');
    }

    public function testAnEntryWithoutAnAlgorithmCountsAsEncrypted(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3()->withFile(
            'META-INF/encryption.xml',
            '<encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#">'
            . '<enc:EncryptedData><enc:CipherData><enc:CipherReference URI="EPUB/text/chapter.xhtml"/></enc:CipherData></enc:EncryptedData></encryption>'
        ));

        $this->assertTrue($epubFile->isDrmProtected());
        $this->assertSame(['EPUB/text/chapter.xhtml'], $epubFile->getEncryptedPaths());
    }

    public function testGetTextSkipsEncryptedDocuments(): void
    {
        $path = $this->twoChapterBook(['EPUB/text/secret.xhtml' => self::AES])->buildEpub($this->tmpDir . '/text.epub');

        $epubFile = EpubFile::open($path);
        $reader = EpubReader::open($path);
        try {
            $this->assertSame(['EPUB/text/chapter.xhtml'], array_keys($epubFile->getText()));
            $this->assertSame($epubFile->getText(), $reader->getText());
            $this->assertSame(['EPUB/text/chapter.xhtml'], array_keys($reader->getText(false)));
        } finally {
            $epubFile->close();
            $reader->close();
        }
    }

    public function testGetTextStillReadsObfuscatedFontBooksAndPlainBooks(): void
    {
        $path = $this->twoChapterBook(['EPUB/fonts/a.otf' => 'http://www.idpf.org/2008/embedding'])->buildEpub($this->tmpDir . '/fonts.epub');

        $epubFile = EpubFile::open($path);
        try {
            $this->assertSame(['EPUB/text/chapter.xhtml', 'EPUB/text/secret.xhtml'], array_keys($epubFile->getText()));
        } finally {
            $epubFile->close();
        }
    }

    public function testContentManagerRefusesTheTextOfAnEncryptedDocument(): void
    {
        $epubFile = EpubFile::open($this->twoChapterBook(['EPUB/text/secret.xhtml' => self::AES])->buildEpub($this->tmpDir . '/cm.epub'));
        try {
            $this->assertSame('Chapter' . "\n" . 'Text.', $epubFile->getContentManager()->getText('EPUB/text/chapter.xhtml'));

            $this->expectException(Exception::class);
            $this->expectExceptionMessage('encrypted');
            $epubFile->getContentManager()->getText('EPUB/text/secret.xhtml');
        } finally {
            $epubFile->close();
        }
    }

    /**
     * A book with a plain chapter and a second spine document that is listed in encryption.xml as given.
     *
     * @param array<string, string> $entries CipherReference URI => algorithm
     */
    private function twoChapterBook(array $entries): EpubBuilder
    {
        $builder = EpubBuilder::epub3();
        $opf = str_replace(
            ['</manifest>', '</spine>'],
            ['<item id="secret" href="text/secret.xhtml" media-type="application/xhtml+xml"/></manifest>', '<itemref idref="secret"/></spine>'],
            (string) $builder->getFile('EPUB/package.opf')
        );

        return $builder
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/text/secret.xhtml', EpubBuilder::xhtml('Secret', '<p>Ciphertext stand-in.</p>'))
            ->withFile('META-INF/encryption.xml', self::encryption($entries));
    }

    public function testTheQueriesNeedALoadedBook(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $epubFile->cleanup();

        $this->expectException(Exception::class);

        $epubFile->isDrmProtected();
    }

    public function testConvertRefusesADrmProtectedBookWithAnyAdapter(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('META-INF/rights.xml', '<rights/>'));
        $output = $this->tmpDir . '/out.pdf';

        $converter = new class () implements ConverterInterface {
            public bool $called = false;

            public function convert(string $epubDirectory, string $outputPath): void
            {
                $this->called = true;
            }
        };

        foreach ([$converter, new DompdfAdapter(), new TCPDFAdapter()] as $adapter) {
            try {
                $epubFile->convert($adapter, $output);
                $this->fail('A DRM-protected book must not be converted');
            } catch (ConversionException $conversionException) {
                $this->assertStringContainsString('DRM-protected', $conversionException->getMessage());
            }
        }

        $this->assertFalse($converter->called);
        $this->assertFileDoesNotExist($output);
    }

    public function testTheAdaptersRefuseADrmProtectedDirectory(): void
    {
        $directory = EpubBuilder::epub3()
            ->withFile('META-INF/encryption.xml', self::encryption(['EPUB/text/chapter.xhtml' => self::AES]))
            ->writeTo($this->tmpDir . '/book');

        foreach ([new DompdfAdapter(), new TCPDFAdapter()] as $adapter) {
            try {
                $adapter->convert($directory, $this->tmpDir . '/out.pdf');
                $this->fail('A DRM-protected book must not be converted');
            } catch (ConversionException $conversionException) {
                $this->assertStringContainsString('DRM-protected', $conversionException->getMessage());
            }
        }

        $this->assertFileDoesNotExist($this->tmpDir . '/out.pdf');
    }

    /**
     * @param array<string, string> $entries CipherReference URI => algorithm
     */
    private static function encryption(array $entries): string
    {
        $data = '';
        foreach ($entries as $uri => $algorithm) {
            $data .= "<enc:EncryptedData><enc:EncryptionMethod Algorithm=\"{$algorithm}\"/>"
                . "<enc:CipherData><enc:CipherReference URI=\"{$uri}\"/></enc:CipherData></enc:EncryptedData>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?><encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container"'
            . ' xmlns:enc="http://www.w3.org/2001/04/xmlenc#">' . $data . '</encryption>';
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }
}
