<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * Obfuscated fonts (OCF "Font Obfuscation") are keyed by the unique identifier, so changing
 * the identifier must re-key them.
 */
final class FontObfuscationTest extends TestCase
{
    private const string IDPF = 'http://www.idpf.org/2008/embedding';

    private const string ADOBE = 'http://ns.adobe.com/pdf/enc#RC';

    private const string OLD_ID = 'urn:uuid:3f1e2a4c-5b6d-4e7f-8a9b-0c1d2e3f4a5b';

    private const string NEW_ID = 'urn:uuid:11111111-2222-3333-4444-555555555555';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'fonts';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testChangingTheIdentifierReKeysIdpfObfuscatedFonts(): void
    {
        $font = random_bytes(3000);
        $short = random_bytes(100);
        $path = $this->book([
            'EPUB/fonts/body.otf' => [self::IDPF, $font],
            'EPUB/fonts/short font.otf' => [self::IDPF, $short],
        ]);

        $epubFile = EpubFile::open($path);
        $epubFile->getMetadata()->setIdentifiers([self::NEW_ID]);
        $epubFile->save();

        $saved = EpubFile::open($path);
        $this->assertSame($font, self::idpf($saved->getContentManager()->getContent('EPUB/fonts/body.otf'), self::NEW_ID));
        $this->assertSame($short, self::idpf($saved->getContentManager()->getContent('EPUB/fonts/short font.otf'), self::NEW_ID));
    }

    public function testChangingTheIdentifierReKeysAdobeObfuscatedFonts(): void
    {
        $font = random_bytes(2000);
        $path = $this->book(['EPUB/fonts/body.otf' => [self::ADOBE, $font]]);

        $epubFile = EpubFile::open($path);
        $epubFile->getMetadata()->setIdentifiers([self::NEW_ID]);
        $epubFile->save();

        $this->assertSame($font, self::adobe($this->savedFont($path), self::NEW_ID));
    }

    public function testAdobeObfuscatedFontsNeedAUuidIdentifier(): void
    {
        $path = $this->book(['EPUB/fonts/body.otf' => [self::ADOBE, random_bytes(2000)]]);
        $epubFile = EpubFile::open($path);
        $epubFile->getMetadata()->setIdentifiers(['isbn:9780000000000']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('EPUB/fonts/body.otf');

        $epubFile->save();
    }

    public function testFontsAreUntouchedWhenTheIdentifierStaysTheSame(): void
    {
        $path = $this->book(['EPUB/fonts/body.otf' => [self::IDPF, random_bytes(2000)]]);
        $epubFile = EpubFile::open($path);
        $obfuscated = $epubFile->getContentManager()->getContent('EPUB/fonts/body.otf');

        $epubFile->getMetadata()->setTitle('Renamed');
        $epubFile->save();

        $this->assertSame($obfuscated, $this->savedFont($path));
    }

    public function testEachSaveReKeysFromTheIdentifierOfThePreviousSave(): void
    {
        $font = random_bytes(2000);
        $path = $this->book(['EPUB/fonts/body.otf' => [self::IDPF, $font]]);

        $epubFile = EpubFile::open($path);
        $epubFile->getMetadata()->setIdentifiers(['urn:isbn:9780000000001']);
        $epubFile->save();
        $epubFile->getMetadata()->setIdentifiers([self::NEW_ID]);
        $epubFile->save();

        $this->assertSame($font, self::idpf($this->savedFont($path), self::NEW_ID));
    }

    public function testReferencesOutsideTheBookAndOtherAlgorithmsAreIgnored(): void
    {
        $font = random_bytes(2000);
        $outside = $this->tmpDir . '/outside.otf';
        file_put_contents($outside, $font);
        $path = $this->book(['EPUB/fonts/body.otf' => ['http://www.w3.org/2001/04/xmlenc#aes128-cbc', $font]], [
            '../../outside.otf' => self::IDPF,
            'EPUB/fonts/missing.otf' => self::IDPF,
        ]);

        $epubFile = EpubFile::open($path);
        $epubFile->getMetadata()->setIdentifiers([self::NEW_ID]);
        $epubFile->save();

        $this->assertSame($font, $this->savedFont($path));
        $this->assertStringEqualsFile($outside, $font);
    }

    /**
     * An EPUB 3 book whose fonts are obfuscated for OLD_ID.
     *
     * @param array<string, array{string, string}> $fonts path => [algorithm, plain font bytes]
     * @param array<string, string> $extraReferences CipherReference URI => algorithm, for files not written
     */
    private function book(array $fonts, array $extraReferences = []): string
    {
        $builder = EpubBuilder::epub3();
        $items = '';
        $data = '';
        $index = 0;
        foreach ($fonts as $fontPath => [$algorithm, $font]) {
            $obfuscated = match ($algorithm) {
                self::IDPF => self::idpf($font, self::OLD_ID),
                self::ADOBE => self::adobe($font, self::OLD_ID),
                default => $font,
            };
            $builder->withFile($fontPath, $obfuscated);
            $items .= '<item id="font' . $index++ . '" href="' . str_replace(' ', '%20', substr($fontPath, strlen('EPUB/'))) . '" media-type="font/otf"/>';
            $data .= self::encryptedData($algorithm, str_replace(' ', '%20', $fontPath));
        }

        foreach ($extraReferences as $uri => $algorithm) {
            $data .= self::encryptedData($algorithm, $uri);
        }

        $builder->withFile('EPUB/package.opf', str_replace('</manifest>', "{$items}</manifest>", (string) $builder->getFile('EPUB/package.opf')));
        $builder->withFile(
            'META-INF/encryption.xml',
            '<?xml version="1.0" encoding="UTF-8"?><encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container"'
            . ' xmlns:enc="http://www.w3.org/2001/04/xmlenc#">' . $data . '</encryption>'
        );

        return $builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub');
    }

    /**
     * The saved font; the reopened book is held while reading, since its destructor deletes its files.
     */
    private function savedFont(string $path): string
    {
        $saved = EpubFile::open($path);

        return $saved->getContentManager()->getContent('EPUB/fonts/body.otf');
    }

    private static function encryptedData(string $algorithm, string $uri): string
    {
        return "<enc:EncryptedData><enc:EncryptionMethod Algorithm=\"{$algorithm}\"/>"
            . "<enc:CipherData><enc:CipherReference URI=\"{$uri}\"/></enc:CipherData></enc:EncryptedData>";
    }

    /**
     * IDPF obfuscation (OCF 3.3): the first 1040 bytes XORed with the SHA-1 of the identifier
     * without whitespace. XOR is its own inverse.
     */
    private static function idpf(string $data, string $identifier): string
    {
        return self::xorHeader($data, sha1(str_replace(["\x20", "\x09", "\x0D", "\x0A"], '', $identifier), true), 1040);
    }

    /**
     * Adobe obfuscation: the first 1024 bytes XORed with the 16 bytes of the identifier's UUID.
     */
    private static function adobe(string $data, string $identifier): string
    {
        return self::xorHeader($data, (string) hex2bin(str_replace('-', '', substr($identifier, strlen('urn:uuid:')))), 1024);
    }

    private static function xorHeader(string $data, string $key, int $length): string
    {
        for ($i = 0; $i < min($length, strlen($data)); $i++) {
            $data[$i] = chr(ord($data[$i]) ^ ord($key[$i % strlen($key)]));
        }

        return $data;
    }
}
