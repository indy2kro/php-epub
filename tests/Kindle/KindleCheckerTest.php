<?php

declare(strict_types=1);

namespace PhpEpub\Test\Kindle;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Kindle\KindleChecker;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ValidationIssue;
use PhpEpub\ValidationProfile;
use PHPUnit\Framework\TestCase;

final class KindleCheckerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'kindle';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testAGoodBookHasNoKindleIssues(): void
    {
        $epub = $this->open($this->goodBook());

        $this->assertSame([], $epub->validate(ValidationProfile::kindle()));
    }

    public function testTheProfileAddsToTheStructuralIssues(): void
    {
        $epub = $this->open($this->goodBook()->withFile('EPUB/extra.css', 'p {}'));

        $codes = $this->codes($epub->validate(ValidationProfile::kindle()));
        $this->assertSame(['FILE_NOT_IN_MANIFEST'], $codes);

        $epub = $this->open(EpubBuilder::epub3());
        $this->assertSame([], $epub->validate());
        $this->assertSame(['KINDLE_AUTHOR_MISSING', 'KINDLE_COVER_MISSING'], $this->codes($epub->validate(ValidationProfile::kindle())));
    }

    public function testEveryIssueHasAKindleCodeAndAFixHint(): void
    {
        $epub = $this->open(EpubBuilder::epub3());

        foreach ($epub->validate(ValidationProfile::kindle()) as $issue) {
            $this->assertStringStartsWith('KINDLE_', $issue->code);
            $this->assertNotSame('', (string) $issue->fix);
            $this->assertSame(ValidationIssue::WARNING, $issue->severity);
        }
    }

    public function testAFileAboveTheWebLimitIsAnError(): void
    {
        $epub = $this->open($this->goodBook());

        $issues = (new KindleChecker($epub, null, 100, 50))->check();

        $this->assertSame(['KINDLE_FILE_TOO_LARGE'], $this->codes($issues));
        $this->assertSame(ValidationIssue::ERROR, $issues[0]->severity);
    }

    public function testAFileAboveTheEmailLimitIsAWarning(): void
    {
        $epub = $this->open($this->goodBook());
        $size = strlen($epub->saveToString());

        $issues = (new KindleChecker($epub, null, $size + 1000, $size - 1))->check();

        $this->assertSame(['KINDLE_FILE_TOO_LARGE_FOR_EMAIL'], $this->codes($issues));
        $this->assertSame(ValidationIssue::WARNING, $issues[0]->severity);
    }

    public function testAMissingAuthorIsAHeuristicWarning(): void
    {
        $epub = $this->open($this->goodBook());
        $epub->getMetadata()->setAuthors([]);

        $this->assertSame(['KINDLE_AUTHOR_MISSING'], $this->codes($epub->validate(ValidationProfile::kindle())));
    }

    public function testAMissingCover(): void
    {
        $epub = $this->open($this->goodBook());
        $epub->removeCoverImage();

        $this->assertSame(['KINDLE_COVER_MISSING'], $this->codes($epub->validate(ValidationProfile::kindle())));
    }

    public function testACoverMustBeJpeg(): void
    {
        $epub = $this->open($this->goodBook());
        $epub->setCoverImage($this->image(1000, 1700, 'png'), 'image/png');

        $this->assertSame(['KINDLE_COVER_TYPE'], $this->codes($epub->validate(ValidationProfile::kindle())));
    }

    public function testASmallCover(): void
    {
        $epub = $this->open($this->goodBook());
        $epub->setCoverImage($this->image(500, 900, 'jpeg'), 'image/jpeg');

        $this->assertSame(['KINDLE_COVER_TOO_SMALL'], $this->codes($epub->validate(ValidationProfile::kindle())));
    }

    public function testACoverAboveTheMaximumSide(): void
    {
        $epub = $this->open($this->goodBook());
        $epub->setCoverImage($this->image(10001, 20, 'jpeg'), 'image/jpeg');

        $codes = $this->codes($epub->validate(ValidationProfile::kindle()));
        $this->assertContains('KINDLE_COVER_TOO_LARGE', $codes);
    }

    public function testACoverThatIsNotTallEnough(): void
    {
        $epub = $this->open($this->goodBook());
        $epub->setCoverImage($this->image(1000, 1000, 'jpeg'), 'image/jpeg');

        $this->assertSame(['KINDLE_COVER_RATIO'], $this->codes($epub->validate(ValidationProfile::kindle())));
    }

    public function testAFixedLayoutBookIsAHeuristicWarning(): void
    {
        $epub = $this->open($this->goodBook());
        $epub->getMetadata()->setRenditionLayout('pre-paginated');

        $this->assertSame(['KINDLE_FIXED_LAYOUT'], $this->codes($epub->validate(ValidationProfile::kindle())));
    }

    public function testADrmProtectedBookIsAHeuristicWarning(): void
    {
        $encryption = '<?xml version="1.0"?><encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#">'
            . '<enc:EncryptedData><enc:EncryptionMethod Algorithm="http://www.w3.org/2001/04/xmlenc#aes128-cbc"/>'
            . '<enc:CipherData><enc:CipherReference URI="EPUB/text/chapter.xhtml"/></enc:CipherData></enc:EncryptedData></encryption>';
        $epub = $this->open($this->goodBook()->withFile('META-INF/encryption.xml', $encryption)->withFile('EPUB/text/chapter.xhtml', "\xFF\xFEbinary"));

        $codes = $this->codes($epub->validate(ValidationProfile::kindle()));

        $this->assertContains('KINDLE_DRM', $codes);
        $this->assertNotContains('KINDLE_NOT_UTF8', $codes, 'Encrypted content is not examined.');
    }

    public function testADocumentThatIsNotUtf8(): void
    {
        $latin1 = '<?xml version="1.0" encoding="ISO-8859-1"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>T</title></head><body><p>caf' . "\xE9" . '</p></body></html>';
        $epub = $this->open($this->goodBook()->withFile('EPUB/text/chapter.xhtml', $latin1));

        $issues = $epub->validate(ValidationProfile::kindle());

        $this->assertContains('KINDLE_NOT_UTF8', $this->codes($issues));
        $this->assertSame('EPUB/text/chapter.xhtml', $this->issue($issues, 'KINDLE_NOT_UTF8')->location);
    }

    public function testADocumentThatDeclaresUtf8ButIsNot(): void
    {
        $bad = '<?xml version="1.0" encoding="UTF-8"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>T</title></head><body><p>caf' . "\xE9" . '</p></body></html>';
        $epub = $this->open($this->goodBook()->withFile('EPUB/text/chapter.xhtml', $bad));

        $this->assertContains('KINDLE_NOT_UTF8', $this->codes($epub->validate(ValidationProfile::kindle())));
    }

    public function testASpaceKindleDoesNotSupport(): void
    {
        $chapter = EpubBuilder::xhtml('Chapter', "<h1>Chapter</h1><p>thin\u{2009}space and no-break\u{00A0}space and \u{200C}joiner</p>", '../css/style.css');
        $epub = $this->open($this->goodBook()->withFile('EPUB/text/chapter.xhtml', $chapter));

        $this->assertSame(['KINDLE_UNSUPPORTED_SPACE'], $this->codes($epub->validate(ValidationProfile::kindle())));
    }

    public function testNoBreakSpaceAndZeroWidthNonJoinerAreFine(): void
    {
        $chapter = EpubBuilder::xhtml('Chapter', "<h1>Chapter</h1><p>no-break\u{00A0}space and \u{200C}joiner</p>", '../css/style.css');
        $epub = $this->open($this->goodBook()->withFile('EPUB/text/chapter.xhtml', $chapter));

        $this->assertSame([], $epub->validate(ValidationProfile::kindle()));
    }

    public function testAScript(): void
    {
        $chapter = EpubBuilder::xhtml('Chapter', '<h1>Chapter</h1><script>var a;</script>', '../css/style.css');
        $epub = $this->open($this->goodBook()->withFile('EPUB/text/chapter.xhtml', $chapter));
        $epub->getManifest()->addProperty('chapter', 'scripted');

        $this->assertSame(['KINDLE_SCRIPTED'], $this->codes($epub->validate(ValidationProfile::kindle())));
    }

    public function testValidatingChangesNothing(): void
    {
        $epub = $this->open($this->goodBook());
        $epub->getMetadata()->setTitle('Edited, not saved');
        $opf = $epub->getTempDir() . '/EPUB/package.opf';
        $before = (string) file_get_contents($opf);
        $files = $this->files((string) $epub->getTempDir());

        $epub->validate(ValidationProfile::kindle());

        $this->assertSame($before, (string) file_get_contents($opf), 'The extraction is untouched.');
        $this->assertSame($files, $this->files((string) $epub->getTempDir()));
        $this->assertTrue($epub->getMetadata()->isModified(), 'The unsaved edit is still pending.');
    }

    public function testACoverOf50MbOrMoreIsTooLarge(): void
    {
        $epub = $this->open($this->goodBook());
        $handle = fopen($epub->getTempDir() . '/EPUB/images/cover.jpg', 'ab');
        $this->assertNotFalse($handle);
        // Zeros after the image: its header stays readable and they pack to almost nothing.
        for ($megabyte = 0; $megabyte < 50; $megabyte++) {
            fwrite($handle, str_repeat("\0", 1048576));
        }

        fclose($handle);

        $this->assertSame(['KINDLE_COVER_TOO_LARGE'], $this->codes($epub->validate(ValidationProfile::kindle())));
    }

    /**
     * @return array<string, int>
     */
    private function files(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[$file->getPathname()] = $file->getSize();
            }
        }

        ksort($files);

        return $files;
    }
    public function testCheckingAnUnloadedBookIsRefused(): void
    {
        $epub = $this->open($this->goodBook());
        $checker = new KindleChecker($epub);
        $epub->cleanup();

        $this->expectException(Exception::class);
        $checker->check();
    }

    /**
     * A book that passes every Kindle check: an author, a 1000 x 1700 JPEG cover and plain UTF-8 documents.
     */
    private function goodBook(): EpubBuilder
    {
        $opf = (string) EpubBuilder::epub3()->getFile('EPUB/package.opf');
        $opf = str_replace('<dc:title>', '<dc:creator>Ann Author</dc:creator><dc:title>', $opf);
        $opf = str_replace('</manifest>', '<item id="cover" href="images/cover.jpg" media-type="image/jpeg" properties="cover-image"/></manifest>', $opf);
        $opf = str_replace('</metadata>', '<meta name="cover" content="cover"/></metadata>', $opf);

        return EpubBuilder::epub3()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/images/cover.jpg', $this->image(1000, 1700, 'jpeg'));
    }

    private function image(int $width, int $height, string $format): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The GD extension is not available.');
        }

        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        $this->assertNotFalse($image);
        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function open(EpubBuilder $book): EpubFile
    {
        return EpubFile::open($book->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }

    /**
     * @param list<ValidationIssue> $issues
     *
     * @return list<string>
     */
    private function codes(array $issues): array
    {
        return array_map(static fn (ValidationIssue $issue): string => $issue->code, $issues);
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    private function issue(array $issues, string $code): ValidationIssue
    {
        foreach ($issues as $issue) {
            if ($issue->code === $code) {
                return $issue;
            }
        }

        $this->fail("No {$code} issue.");
    }
}
