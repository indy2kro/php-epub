<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\ContentManager;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\FontObfuscation;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * ContentManager::addFont() and getFontData(): obfuscated fonts are added and read back plain.
 */
final class FontsTest extends TestCase
{
    private const string UID = 'urn:uuid:3f1e2a4c-5b6d-4e7f-8a9b-0c1d2e3f4a5b';

    private const string OTHER_UID = 'urn:uuid:11111111-2222-3333-4444-555555555555';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'addfont';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testAnAddedFontIsObfuscatedListedAndReadBackPlain(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $font = random_bytes(3000);

        $epubFile->getContentManager()->addFont('EPUB/fonts/body.otf', $font);

        $content = $epubFile->getContentManager();
        $stored = $content->getContent('EPUB/fonts/body.otf');
        $this->assertNotSame($font, $stored);
        $this->assertSame(substr($font, 1040), substr($stored, 1040));
        $this->assertSame($font, FontObfuscation::apply($stored, FontObfuscation::IDPF, (string) FontObfuscation::key(FontObfuscation::IDPF, self::UID)));
        $this->assertSame($font, $content->getFontData('EPUB/fonts/body.otf'));
        $this->assertSame('font/otf', $epubFile->getManifest()->findByPath('EPUB/fonts/body.otf')?->mediaType);

        $encryption = (string) file_get_contents($epubFile->getTempDir() . '/META-INF/encryption.xml');
        $this->assertStringContainsString('xmlns:enc="http://www.w3.org/2001/04/xmlenc#"', $encryption);
        $this->assertStringContainsString('Algorithm="' . FontObfuscation::IDPF . '"', $encryption);
        $this->assertStringContainsString('URI="EPUB/fonts/body.otf"', $encryption);
        $this->assertSame([], $epubFile->validate());
        $this->assertFalse($epubFile->isDrmProtected());
    }

    public function testAnAddedFontSurvivesSavingAndAnIdentifierChange(): void
    {
        $path = $this->tmpDir . '/saved.epub';
        $epubFile = $this->open(EpubBuilder::epub3());
        $font = random_bytes(2500);
        $epubFile->getContentManager()->addFont('EPUB/fonts/a font.otf', $font);

        $epubFile->getMetadata()->setIdentifiers([self::OTHER_UID]);
        $epubFile->getContentManager()->addFont('EPUB/fonts/second.otf', $font);
        $epubFile->save($path);

        $saved = EpubFile::open($path);
        $this->assertSame($font, $saved->getContentManager()->getFontData('EPUB/fonts/a font.otf'));
        $this->assertSame($font, $saved->getContentManager()->getFontData('EPUB/fonts/second.otf'));
        $this->assertStringContainsString('URI="EPUB/fonts/a%20font.otf"', (string) file_get_contents($saved->getTempDir() . '/META-INF/encryption.xml'));

        // The font is keyed for the new identifier now: a later save and change keep working.
        $saved->getMetadata()->setIdentifiers([self::UID]);
        $saved->save($path);
        $this->assertSame($font, EpubFile::open($path)->getContentManager()->getFontData('EPUB/fonts/second.otf'));
    }

    public function testAddingAFontKeepsTheOtherEntriesAndUpdatesItsOwn(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3()->withFile(
            'META-INF/encryption.xml',
            self::encryption(
                '<enc:EncryptedData><enc:EncryptionMethod Algorithm="http://ns.adobe.com/pdf/enc#RC"/><enc:CipherData><enc:CipherReference URI="EPUB/fonts/old.otf"/></enc:CipherData></enc:EncryptedData>'
                . '<enc:EncryptedData><enc:CipherData><enc:CipherReference URI="EPUB/fonts/bare.otf"/></enc:CipherData></enc:EncryptedData>'
                . '<enc:EncryptedData><enc:EncryptionMethod Algorithm="http://www.w3.org/2001/04/xmlenc#aes128-cbc"/></enc:EncryptedData>'
                . '<enc:EncryptedData><enc:CipherData><enc:CipherReference URI="../outside.otf"/></enc:CipherData></enc:EncryptedData>'
            )
        ));
        $content = $epubFile->getContentManager();
        $font = random_bytes(1500);

        $content->addFont('EPUB/fonts/old.otf', $font);
        $content->addFont('EPUB/fonts/bare.otf', $font);

        $encryption = (string) file_get_contents($epubFile->getTempDir() . '/META-INF/encryption.xml');
        $this->assertSame(2, substr_count($encryption, 'Algorithm="' . FontObfuscation::IDPF . '"'));
        $this->assertStringNotContainsString('ns.adobe.com', $encryption);
        $this->assertStringContainsString('aes128-cbc', $encryption);
        $this->assertSame($font, $content->getFontData('EPUB/fonts/old.otf'));
        $this->assertSame($font, $content->getFontData('EPUB/fonts/bare.otf'));
    }

    public function testAPlainFontIsStoredAsItIsAndDropsItsEntry(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $content = $epubFile->getContentManager();
        $font = random_bytes(2000);

        $content->addFont('EPUB/fonts/plain.otf', $font, false);
        $this->assertFileDoesNotExist($epubFile->getTempDir() . '/META-INF/encryption.xml');
        $this->assertSame($font, $content->getContent('EPUB/fonts/plain.otf'));
        $this->assertSame($font, $content->getFontData('EPUB/fonts/plain.otf'));

        $content->addFont('EPUB/fonts/plain.otf', $font);
        $this->assertNotSame($font, $content->getContent('EPUB/fonts/plain.otf'));

        $content->addFont('EPUB/fonts/plain.otf', $font, false);
        $this->assertSame($font, $content->getContent('EPUB/fonts/plain.otf'));
        $this->assertSame($font, $content->getFontData('EPUB/fonts/plain.otf'));
        $this->assertStringNotContainsString('plain.otf', (string) file_get_contents($epubFile->getTempDir() . '/META-INF/encryption.xml'));
    }

    public function testAdobeObfuscatedFontsAreReadBackPlain(): void
    {
        $font = random_bytes(2000);
        $key = (string) FontObfuscation::key(FontObfuscation::ADOBE, self::UID);
        $epubFile = $this->open(EpubBuilder::epub3()
            ->withFile('EPUB/fonts/adobe.otf', FontObfuscation::apply($font, FontObfuscation::ADOBE, $key))
            ->withFile('META-INF/encryption.xml', self::encryption(
                '<enc:EncryptedData><enc:EncryptionMethod Algorithm="' . FontObfuscation::ADOBE . '"/><enc:CipherData><enc:CipherReference URI="EPUB/fonts/adobe.otf"/></enc:CipherData></enc:EncryptedData>'
            )));

        $this->assertSame($font, $epubFile->getContentManager()->getFontData('EPUB/fonts/adobe.otf'));
    }

    public function testAnAdobeFontNeedsAUuidIdentifierToBeRead(): void
    {
        $builder = EpubBuilder::epub3();
        $epubFile = $this->open($builder
            ->withFile('EPUB/package.opf', str_replace(self::UID, 'isbn:9780000000000', (string) $builder->getFile('EPUB/package.opf')))
            ->withFile('EPUB/fonts/adobe.otf', random_bytes(2000))
            ->withFile('META-INF/encryption.xml', self::encryption(
                '<enc:EncryptedData><enc:EncryptionMethod Algorithm="' . FontObfuscation::ADOBE . '"/><enc:CipherData><enc:CipherReference URI="EPUB/fonts/adobe.otf"/></enc:CipherData></enc:EncryptedData>'
            )));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('urn:uuid');

        $epubFile->getContentManager()->getFontData('EPUB/fonts/adobe.otf');
    }

    public function testAFontOfADrmBookIsReturnedAsItIs(): void
    {
        $font = random_bytes(500);
        $epubFile = $this->open(EpubBuilder::epub3()
            ->withFile('EPUB/fonts/drm.otf', $font)
            ->withFile('META-INF/encryption.xml', self::encryption(
                '<enc:EncryptedData><enc:EncryptionMethod Algorithm="http://www.w3.org/2001/04/xmlenc#aes128-cbc"/><enc:CipherData><enc:CipherReference URI="EPUB/fonts/drm.otf"/></enc:CipherData></enc:EncryptedData>'
            )));

        $this->assertSame($font, $epubFile->getContentManager()->getFontData('EPUB/fonts/drm.otf'));
    }

    public function testAFontCannotBeObfuscatedWithoutAnIdentifier(): void
    {
        $directory = EpubBuilder::epub3()->writeTo($this->tmpDir . '/book');
        $content = new ContentManager($directory);

        $content->addFont('EPUB/fonts/plain.otf', 'font', false);
        $this->assertSame('font', $content->getFontData('EPUB/fonts/plain.otf'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('unique identifier');

        $content->addFont('EPUB/fonts/body.otf', 'font');
    }

    public function testAFontCannotBeObfuscatedWithABlankIdentifier(): void
    {
        $directory = EpubBuilder::epub3()->writeTo($this->tmpDir . '/book');
        $content = new ContentManager($directory, null, null, new \PhpEpub\Util\PathResolver(), static fn (): string => ' ');

        $this->expectException(Exception::class);

        $content->addFont('EPUB/fonts/body.otf', 'font');
    }

    public function testNothingIsWrittenWhenEncryptionXmlCannotBeParsed(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3()->withFile('META-INF/encryption.xml', '<encryption><broken'));

        try {
            $epubFile->getContentManager()->addFont('EPUB/fonts/body.otf', 'font');
            $this->fail('A broken encryption.xml must be reported');
        } catch (Exception) {
            $this->assertFileDoesNotExist($epubFile->getTempDir() . '/EPUB/fonts/body.otf');
        }
    }

    public function testFontsCannotGoIntoTheContainerDirectory(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('container');

        $epubFile->getContentManager()->addFont('META-INF/body.otf', 'font');
    }

    public function testAMovedObfuscatedFontStaysReadable(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $content = $epubFile->getContentManager();
        $font = random_bytes(2000);
        $content->addFont('EPUB/fonts/body.otf', $font);

        $content->moveContent('EPUB/fonts/body.otf', 'EPUB/fonts/renamed font.otf');

        $this->assertSame($font, $content->getFontData('EPUB/fonts/renamed font.otf'));
    }

    private static function encryption(string $data): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container"'
            . ' xmlns:enc="http://www.w3.org/2001/04/xmlenc#">' . $data . '</encryption>';
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }
}
