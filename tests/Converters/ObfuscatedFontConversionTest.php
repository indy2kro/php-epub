<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use Dompdf\Options;
use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\Converters\EpubDocumentLoader;
use PhpEpub\FontObfuscation;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * The PDF renderers get the book's obfuscated fonts de-obfuscated (as data: URIs), and nothing is
 * written into the book. Only Dompdf loads @font-face fonts; TCPDF ignores them.
 */
final class ObfuscatedFontConversionTest extends TestCase
{
    private const string UID = 'urn:uuid:3f1e2a4c-5b6d-4e7f-8a9b-0c1d2e3f4a5b';

    private string $tmpDir;

    private string $font;

    /**
     * A family name of this run only, so nothing but the book can supply the font.
     */
    private string $family;


    protected function setUp(): void
    {
        $this->family = 'EpubFace' . bin2hex(random_bytes(4));

        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'obfuscated-fonts';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }

        // A real font that is not Dompdf's default, so the PDF shows whether the book's font was used.
        $this->font = (string) file_get_contents(dirname(__DIR__, 2) . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSerif.ttf');
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testIdpfObfuscatedFontsAreInlinedPlain(): void
    {
        $directory = $this->book(FontObfuscation::IDPF)->writeTo($this->tmpDir . '/book');
        $before = $this->snapshot($directory);

        $styles = (new EpubDocumentLoader())->load($directory)->styles;

        $this->assertSame($this->font, $this->inlinedFont(implode("\n", $styles), 2));
        $this->assertSame($before, $this->snapshot($directory), 'The book must not be changed');
    }

    public function testAdobeObfuscatedFontsAreInlinedPlain(): void
    {
        $directory = $this->book(FontObfuscation::ADOBE)->writeTo($this->tmpDir . '/book');

        $styles = (new EpubDocumentLoader())->load($directory)->styles;

        $this->assertSame($this->font, $this->inlinedFont(implode("\n", $styles), 2));
    }

    public function testAFontThatIsNotListedAsObfuscatedIsReferencedByPath(): void
    {
        $directory = $this->book(FontObfuscation::IDPF, false)->writeTo($this->tmpDir . '/book');

        $css = implode("\n", (new EpubDocumentLoader())->load($directory)->styles);

        $this->assertStringContainsString('url("' . str_replace('\\', '/', (string) realpath($directory)) . '/EPUB/fonts/face.ttf")', $css);
        $this->assertStringNotContainsString('data:font', $css);
    }

    public function testADamagedEncryptionFileLeavesTheFontAsItIs(): void
    {
        $directory = $this->book(FontObfuscation::IDPF)
            ->withFile('META-INF/encryption.xml', '<encryption><broken')
            ->writeTo($this->tmpDir . '/book');

        $css = implode("\n", (new EpubDocumentLoader())->load($directory)->styles);

        $this->assertStringContainsString('fonts/face.ttf")', $css);
        $this->assertStringNotContainsString('data:font', $css);
    }

    public function testAFontWithoutAKeyIsBlanked(): void
    {
        // Adobe's algorithm needs a UUID identifier.
        $builder = $this->book(FontObfuscation::ADOBE);
        $directory = $builder
            ->withFile('EPUB/package.opf', str_replace(self::UID, 'isbn:9780000000000', (string) $builder->getFile('EPUB/package.opf')))
            ->writeTo($this->tmpDir . '/book');

        $css = implode("\n", (new EpubDocumentLoader())->load($directory)->styles);

        $this->assertStringContainsString('src: url("")', $css);
    }

    public function testDompdfRendersTheBookWithItsObfuscatedFont(): void
    {
        $directory = $this->book(FontObfuscation::IDPF)->writeTo($this->tmpDir . '/book');
        $pdf = $this->tmpDir . '/out.pdf';
        $dompdfFonts = glob((new Options())->getFontDir() . '/*') ?: [];

        (new DompdfAdapter())->convert($directory, $pdf);

        $this->assertStringContainsString('DejaVuSerif', (string) file_get_contents($pdf));
        $this->assertSame([], glob($directory . '/*.pdf') ?: [], 'Nothing is written next to the book');
        // The book's font is registered in a private font directory, never in Dompdf's own.
        $this->assertSame($dompdfFonts, glob((new Options())->getFontDir() . '/*') ?: []);
    }

    public function testDompdfCannotUseAnObfuscatedFontItDoesNotUnlock(): void
    {
        $directory = $this->book(FontObfuscation::IDPF, false)->writeTo($this->tmpDir . '/book');
        $pdf = $this->tmpDir . '/out.pdf';

        (new DompdfAdapter())->convert($directory, $pdf);

        $this->assertStringNotContainsString('DejaVuSerif', (string) file_get_contents($pdf));
    }

    /**
     * A book whose stylesheet declares the font twice (two weights of one file).
     *
     * @param bool $listed Whether encryption.xml lists the font.
     */
    private function book(string $algorithm, bool $listed = true): EpubBuilder
    {
        $key = (string) FontObfuscation::key($algorithm, self::UID);
        $builder = EpubBuilder::epub3();
        $css = '@font-face { font-family: "' . $this->family . '"; src: url("../fonts/face.ttf"); }'
            . ' @font-face { font-family: "' . $this->family . '"; font-weight: bold; src: url("../fonts/face.ttf"); }'
            . ' p { font-family: "' . $this->family . '"; }';
        $builder
            ->withFile('EPUB/css/style.css', $css)
            ->withFile('EPUB/fonts/face.ttf', FontObfuscation::apply($this->font, $algorithm, $key))
            ->withFile('EPUB/package.opf', str_replace(
                '</manifest>',
                '<item id="face" href="fonts/face.ttf" media-type="font/ttf"/></manifest>',
                (string) $builder->getFile('EPUB/package.opf')
            ));

        return $listed ? $builder->withFile('META-INF/encryption.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#">'
            . '<enc:EncryptedData><enc:EncryptionMethod Algorithm="' . $algorithm . '"/>'
            . '<enc:CipherData><enc:CipherReference URI="EPUB/fonts/face.ttf"/></enc:CipherData></enc:EncryptedData></encryption>') : $builder;
    }

    /**
     * The font bytes behind the font data: URIs in the CSS, which must be $count identical ones.
     */
    private function inlinedFont(string $css, int $count): string
    {
        $this->assertSame($count, preg_match_all('#url\("data:font/ttf;base64,([A-Za-z0-9+/=]+)"\)#', $css, $matches));
        $this->assertSame($matches[1][0], $matches[1][1]);

        return (string) base64_decode($matches[1][0], true);
    }

    /**
     * @return array<string, string> path => content hash
     */
    private function snapshot(string $directory): array
    {
        $files = [];
        foreach (glob($directory . '/{,*/,*/*/}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                $files[substr($file, strlen($directory))] = (string) md5_file($file);
            }
        }

        return $files;
    }
}
