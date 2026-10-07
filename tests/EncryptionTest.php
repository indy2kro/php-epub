<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\ConversionException;
use PhpEpub\Converters\ConverterInterface;
use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\Converters\TCPDFAdapter;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
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

    public function testADamagedEncryptionFileDoesNotStopTheBookFromLoading(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('META-INF/encryption.xml', '<encryption><broken'));

        $this->assertFalse($epubFile->isDrmProtected());
        $this->assertSame([], $epubFile->getEncryptedPaths());
        $this->assertSame([], $epubFile->validate());
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
