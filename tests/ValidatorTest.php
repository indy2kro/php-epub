<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ValidationIssue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'validate';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testAValidBookHasNoIssues(): void
    {
        $this->assertSame([], $this->open(EpubBuilder::epub3())->validate());
    }

    public function testABookCreatedFromScratchIsValidOnceItHasAChapterAndAccessibilityMetadata(): void
    {
        $epubFile = EpubFile::create($this->tmpDir . '/new.epub', 'New');
        $accessibility = ['ACCESSIBILITY_ACCESS_MODE_MISSING', 'ACCESSIBILITY_FEATURE_MISSING', 'ACCESSIBILITY_HAZARD_MISSING', 'ACCESSIBILITY_SUMMARY_MISSING'];
        $this->assertSame(['SPINE_EMPTY', 'NAV_EMPTY', ...$accessibility], $this->codes($epubFile->validate()));

        $epubFile->addChapter('One', '<p>One</p>');
        $this->assertSame($accessibility, $this->codes($epubFile->validate()));

        $metadata = $epubFile->getMetadata();
        $metadata->setAccessModes(['textual']);
        $metadata->setAccessibilityFeatures(['none']);
        $metadata->setAccessibilityHazards(['none']);
        $metadata->setAccessibilitySummary('Plain text.');

        $this->assertSame([], $epubFile->validate());
    }

    #[DataProvider('invalidFileNames')]
    public function testWarnsAboutFileNamesOcfForbids(string $name): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        // Written straight into the extraction: some systems cannot even create these files.
        $written = @file_put_contents($epubFile->getTempDir() . '/EPUB/' . $name, 'x') !== false;
        if (! $written || ! in_array($name, (array) scandir($epubFile->getTempDir() . '/EPUB'), true)) {
            $this->markTestSkipped('This system cannot create a file named ' . json_encode($name));
        }

        $issues = array_values(array_filter($epubFile->validate(), static fn (ValidationIssue $issue): bool => $issue->code === 'FILE_NAME_INVALID'));

        $this->assertCount(1, $issues);
        $this->assertSame(ValidationIssue::WARNING, $issues[0]->severity);
        $this->assertSame('EPUB/' . $name, $issues[0]->location);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function invalidFileNames(): \Iterator
    {
        yield 'DEL' => ["a\x7f.txt"];
        yield 'control character' => ["a\x01.txt"];
        yield 'quote' => ['a"b.txt'];
        yield 'asterisk' => ['a*b.txt'];
        yield 'colon' => ['a:b.txt'];
        yield 'less-than' => ['a<b.txt'];
        yield 'question mark' => ['a?b.txt'];
        yield 'backslash' => ['a\\b.txt'];
        yield 'trailing dot' => ['a.'];
    }

    /**
     * @param list<string> $expectedCodes
     */
    #[DataProvider('brokenBooks')]
    public function testReportsProblems(EpubBuilder $builder, array $expectedCodes): void
    {
        $this->assertSame($expectedCodes, $this->codes($this->open($builder)->validate()));
    }

    /**
     * @return iterable<string, array{EpubBuilder, list<string>}>
     */
    public static function brokenBooks(): iterable
    {
        $opf = (string) EpubBuilder::epub3()->getFile('EPUB/package.opf');
        $book = static fn (string $package): EpubBuilder => EpubBuilder::epub3()->withFile('EPUB/package.opf', $package);

        yield 'missing title' => [$book(str_replace('<dc:title>Valid Book</dc:title>', '', $opf)), ['METADATA_TITLE_MISSING']];
        yield 'missing language' => [$book(str_replace('<dc:language>en</dc:language>', '', $opf)), ['METADATA_LANGUAGE_MISSING']];
        yield 'missing identifier' => [$book((string) preg_replace('#<dc:identifier id="uid">[^<]*</dc:identifier>#', '', $opf)), ['METADATA_IDENTIFIER_MISSING']];
        yield 'unique identifier points nowhere' => [$book(str_replace('unique-identifier="uid"', 'unique-identifier="other"', $opf)), ['METADATA_UNIQUE_IDENTIFIER']];
        yield 'no modified date' => [$book((string) preg_replace('#<meta property="dcterms:modified">[^<]*</meta>#', '', $opf)), ['METADATA_MODIFIED_MISSING']];
        yield 'missing file' => [$book(str_replace('</manifest>', '<item id="gone" href="gone.png" media-type="image/png"/></manifest>', $opf)), ['MANIFEST_FILE_MISSING']];
        yield 'href outside the book' => [$book(str_replace('</manifest>', '<item id="out" href="../../out.png" media-type="image/png"/></manifest>', $opf)), ['MANIFEST_HREF_OUTSIDE']];
        yield 'duplicate id' => [$book(str_replace('<dc:title>', '<dc:title id="chapter">', $opf)), ['DUPLICATE_ID']];
        yield 'spine idref unknown' => [$book(str_replace('<itemref idref="chapter"/>', '<itemref idref="chapter"/><itemref idref="nope"/>', $opf)), ['SPINE_UNKNOWN_IDREF']];
        yield 'spine idref twice' => [$book(str_replace('<itemref idref="chapter"/>', '<itemref idref="chapter"/><itemref idref="chapter"/>', $opf)), ['SPINE_DUPLICATE_IDREF']];
        yield 'stylesheet in the spine' => [$book(str_replace('<itemref idref="chapter"/>', '<itemref idref="chapter"/><itemref idref="style"/>', $opf)), ['SPINE_NOT_CONTENT']];
        yield 'no navigation document' => [
            $book(str_replace(' properties="nav"', '', $opf)),
            ['NAV_MISSING'],
        ];
        yield 'missing mimetype' => [EpubBuilder::epub3()->withoutFile('mimetype'), ['MIMETYPE_INVALID']];
        yield 'padded mimetype' => [EpubBuilder::epub3()->withFile('mimetype', "application/epub+zip\n"), ['MIMETYPE_INVALID']];
        $withNcx = $book(str_replace('</manifest>', '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/></manifest>', $opf));
        yield 'NCX not well-formed' => [(clone $withNcx)->withFile('EPUB/toc.ncx', '<ncx'), ['NCX_INVALID']];
        yield 'NCX without navMap' => [(clone $withNcx)->withFile('EPUB/toc.ncx', '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"/>'), ['NCX_INVALID']];
        yield 'NCX without namespace' => [(clone $withNcx)->withFile('EPUB/toc.ncx', '<ncx version="2005-1"><navMap/></ncx>'), ['NCX_INVALID']];
        yield 'navigation document not well-formed' => [EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', '<html><body><nav'), ['NAV_INVALID']];
        $chapter = static fn (string $body): EpubBuilder => EpubBuilder::epub3()
            ->withFile('EPUB/text/chapter.xhtml', EpubBuilder::xhtml('Chapter', $body, '../css/style.css'));
        yield 'content not well-formed' => [EpubBuilder::epub3()->withFile('EPUB/text/chapter.xhtml', '<html><body><p>Open'), ['CONTENT_NOT_WELL_FORMED']];
        yield 'image missing' => [$chapter('<img src="../images/gone.png" alt=""/>'), ['CONTENT_REFERENCE_MISSING']];
        yield 'link to a missing chapter' => [$chapter('<a href="missing.xhtml#part">Next</a>'), ['CONTENT_REFERENCE_MISSING']];
        yield 'reference outside the book' => [$chapter('<img src="../../../../outside.png" alt=""/>'), ['CONTENT_REFERENCE_MISSING']];
        yield 'image not in the manifest' => [
            $chapter('<img src="../images/extra.png" alt=""/>')->withFile('EPUB/images/extra.png', (string) base64_decode(EpubBuilder::PNG, true)),
            ['FILE_NOT_IN_MANIFEST', 'CONTENT_REFERENCE_NOT_IN_MANIFEST'],
        ];
        yield 'remote, data, fragment and in-book references are fine' => [
            $chapter('<p id="top"><a href="https://example.com/">Web</a><a href="#top">Top</a><a href="chapter.xhtml#top">Self</a>'
                . '<img src="data:image/png;base64,' . EpubBuilder::PNG . '" alt=""/><a href="mailto:a@example.com">Mail</a></p>'),
            [],
        ];
        yield 'file not in the manifest' => [EpubBuilder::epub3()->withFile('EPUB/extra.css', 'p {}'), ['FILE_NOT_IN_MANIFEST']];
        yield 'toc link to an unlisted file' => [
            EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml(
                'Contents',
                '<nav epub:type="toc"><ol><li><a href="text/chapter.xhtml">Chapter</a></li><li><a href="text/missing.xhtml">Missing</a></li></ol></nav>'
            )),
            ['TOC_LINK_NOT_IN_MANIFEST'],
        ];
    }

    /**
     * @param list<string> $expectedCodes
     */
    #[DataProvider('mediaTypeBooks')]
    public function testChecksDeclaredMediaTypesAgainstContent(string $mediaType, string $content, array $expectedCodes): void
    {
        $opf = str_replace('</manifest>', "<item id=\"asset\" href=\"images/asset.bin\" media-type=\"{$mediaType}\"/></manifest>", (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $book = EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf)->withFile('EPUB/images/asset.bin', $content);

        $issues = $this->open($book)->validate();

        $this->assertSame($expectedCodes, $this->codes($issues));
        if ($expectedCodes !== []) {
            $this->assertSame(ValidationIssue::ERROR, $issues[0]->severity);
            $this->assertSame('EPUB/images/asset.bin', $issues[0]->location);
        }
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function mediaTypeBooks(): iterable
    {
        $png = (string) base64_decode(EpubBuilder::PNG, true);
        $jpeg = (string) base64_decode(EpubBuilder::JPEG, true);
        $gif = (string) base64_decode(EpubBuilder::GIF, true);
        $webp = (string) base64_decode(EpubBuilder::WEBP, true);

        yield 'png as png' => ['image/png', $png, []];
        yield 'jpeg as jpeg' => ['image/jpeg', $jpeg, []];
        yield 'gif as gif' => ['image/gif', $gif, []];
        yield 'webp as webp' => ['image/webp', $webp, []];
        yield 'jpeg declared as png' => ['image/png', $jpeg, ['MEDIA_TYPE_MISMATCH']];
        yield 'png declared as jpeg' => ['image/jpeg', $png, ['MEDIA_TYPE_MISMATCH']];
        yield 'png declared as gif' => ['image/gif', $png, ['MEDIA_TYPE_MISMATCH']];
        yield 'gif declared as webp' => ['image/webp', $gif, ['MEDIA_TYPE_MISMATCH']];
        yield 'webp declared as png' => ['image/png', $webp, ['MEDIA_TYPE_MISMATCH']];
        yield 'bytes that are no image are not judged' => ['image/png', 'not an image', []];
        yield 'an empty image file is not judged' => ['image/jpeg', '', []];
        yield 'other types are not judged' => ['application/octet-stream', $png, []];
        yield 'a huge file is only read in part' => ['image/png', $png . random_bytes(2 * 1024 * 1024), []];
        yield 'image declared as XHTML' => ['application/xhtml+xml', $png, ['CONTENT_NOT_WELL_FORMED', 'MEDIA_TYPE_MISMATCH']];
        yield 'plain text declared as XHTML' => ['application/xhtml+xml', "  \n plain words", ['CONTENT_NOT_WELL_FORMED', 'MEDIA_TYPE_MISMATCH']];
        yield 'XHTML after a byte order mark' => ['application/xhtml+xml', "\xEF\xBB\xBF\n<html xmlns=\"http://www.w3.org/1999/xhtml\"><head><title>T</title></head><body/></html>", []];
        yield 'broken XML is not a media type problem' => ['application/xhtml+xml', '<html><body><p>Open', ['CONTENT_NOT_WELL_FORMED']];
    }

    /**
     * @param list<string> $expectedCodes
     */
    #[DataProvider('propertyBooks')]
    public function testChecksManifestPropertiesOfContentDocuments(string $body, string $properties, array $expectedCodes): void
    {
        $opf = str_replace('<item id="chapter" href="text/chapter.xhtml" media-type="application/xhtml+xml"/>', '<item id="chapter" href="text/chapter.xhtml" media-type="application/xhtml+xml"' . $properties . '/>', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $book = EpubBuilder::epub3()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/text/chapter.xhtml', EpubBuilder::xhtml('Chapter', $body, '../css/style.css'));

        $issues = $this->open($book)->validate();

        $this->assertSame($expectedCodes, $this->codes($issues));
        foreach ($issues as $issue) {
            $this->assertSame('EPUB/text/chapter.xhtml', $issue->location);
            $this->assertSame($issue->code === 'MANIFEST_PROPERTY_UNNEEDED' ? ValidationIssue::WARNING : ValidationIssue::ERROR, $issue->severity);
        }
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function propertyBooks(): iterable
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1"/></svg>';
        $math = '<math xmlns="http://www.w3.org/1998/Math/MathML"><mi>x</mi></math>';

        yield 'plain document, no properties' => ['<p>Text</p>', '', []];
        yield 'svg without its property' => [$svg, '', ['MANIFEST_PROPERTY_MISSING']];
        yield 'svg with its property' => [$svg, ' properties="svg"', []];
        yield 'mathml without its property' => [$math, '', ['MANIFEST_PROPERTY_MISSING']];
        yield 'script without its property' => ['<script>var a = 1;</script>', '', ['MANIFEST_PROPERTY_MISSING']];
        yield 'script with its property' => ['<script>var a = 1;</script>', ' properties="scripted"', []];
        yield 'remote image without its property' => ['<img src="https://example.com/a.png" alt=""/>', '', ['MANIFEST_PROPERTY_MISSING']];
        yield 'remote link is only followed' => ['<a href="https://example.com/">Web</a>', '', []];
        yield 'every property missing' => [$svg . $math . '<script/><img src="https://example.com/b.png" alt=""/>', '', ['MANIFEST_PROPERTY_MISSING', 'MANIFEST_PROPERTY_MISSING', 'MANIFEST_PROPERTY_MISSING', 'MANIFEST_PROPERTY_MISSING']];
        yield 'one of two missing' => [$svg . $math, ' properties="svg"', ['MANIFEST_PROPERTY_MISSING']];
        yield 'property not needed' => ['<p>Text</p>', ' properties="svg"', ['MANIFEST_PROPERTY_UNNEEDED']];
        yield 'properties of other kinds are left alone' => ['<p>Text</p>', ' properties="cover-image"', ['COVER_NOT_IMAGE']];
        yield 'not well-formed content is reported once' => ['<p>Open', '', ['CONTENT_NOT_WELL_FORMED']];
    }

    /**
     * Builds a document over 8 MiB several times over; a process of its own keeps that peak out of the shared suite.
     */
    #[RunInSeparateProcess]
    public function testHugeDocumentsAreNotExaminedForManifestProperties(): void
    {
        $padding = '<!--' . chunk_split(base64_encode(random_bytes(7 * 1024 * 1024)), 76, ' ') . '-->';
        $book = EpubBuilder::epub3()->withFile(
            'EPUB/text/chapter.xhtml',
            EpubBuilder::xhtml('Chapter', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>' . $padding, '../css/style.css')
        );

        $this->assertSame([], $this->open($book)->validate());
    }

    public function testManifestPropertiesAreAnEpub3Matter(): void
    {
        $book = EpubBuilder::epub2()->withFile('OEBPS/text/chapter.xhtml', EpubBuilder::xhtml('Chapter', '<script/>'));

        $this->assertSame([], $this->open($book)->validate());
    }

    public function testACoverImagePropertyNeedsAnImage(): void
    {
        $opf = str_replace('media-type="text/css"/>', 'media-type="text/css" properties="cover-image"/><item id="cover" href="cover.png" media-type="image/png" properties="cover-image"/>', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));
        $book = EpubBuilder::epub3()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/cover.png', (string) base64_decode(EpubBuilder::PNG, true));

        $issues = $this->open($book)->validate();

        $this->assertSame(['COVER_NOT_IMAGE'], $this->codes($issues));
        $this->assertSame(ValidationIssue::ERROR, $issues[0]->severity);
        $this->assertSame('EPUB/css/style.css', $issues[0]->location);
    }

    public function testInvalidLanguagesAndDatesAreReported(): void
    {
        $opf = str_replace(
            ['<dc:language>en</dc:language>', '</metadata>'],
            ['<dc:language>en</dc:language><dc:language>English</dc:language><dc:language>fr</dc:language><dc:date>July 2020</dc:date><dc:date>2020-07-31</dc:date><dc:date> </dc:date>', '</metadata>'],
            (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
        );

        $issues = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf))->validate();

        $this->assertSame(['METADATA_LANGUAGE_INVALID', 'METADATA_DATE_INVALID', 'METADATA_DATE_INVALID'], $this->codes($issues));
        $this->assertSame([ValidationIssue::ERROR, ValidationIssue::WARNING, ValidationIssue::WARNING], array_map(static fn (ValidationIssue $issue): string => $issue->severity, $issues));
        $this->assertSame(['English', 'July 2020', ''], array_map(static fn (ValidationIssue $issue): ?string => $issue->location, $issues));
    }

    public function testEmptyLanguageIsReportedAsMissingNotAsInvalid(): void
    {
        $opf = str_replace('<dc:language>en</dc:language>', '<dc:language> </dc:language>', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));

        $this->assertSame(['METADATA_LANGUAGE_MISSING'], $this->codes($this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf))->validate()));
    }

    /**
     * @param list<string> $removed
     * @param list<string> $expectedCodes
     */
    #[DataProvider('accessibilityGaps')]
    public function testWarnsAboutMissingAccessibilityMetadataInEpub3(array $removed, array $expectedCodes): void
    {
        $opf = (string) EpubBuilder::epub3()->getFile('EPUB/package.opf');
        foreach ($removed as $property) {
            $opf = (string) preg_replace('#<meta property="schema:' . $property . '">[^<]*</meta>#', '', $opf);
        }

        $issues = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf))->validate();

        $this->assertSame($expectedCodes, $this->codes($issues));
        foreach ($issues as $issue) {
            $this->assertSame(ValidationIssue::WARNING, $issue->severity);
        }
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function accessibilityGaps(): iterable
    {
        yield 'complete' => [[], []];
        yield 'no access mode' => [['accessMode'], ['ACCESSIBILITY_ACCESS_MODE_MISSING']];
        yield 'no feature' => [['accessibilityFeature'], ['ACCESSIBILITY_FEATURE_MISSING']];
        yield 'no hazard' => [['accessibilityHazard'], ['ACCESSIBILITY_HAZARD_MISSING']];
        yield 'no summary' => [['accessibilitySummary'], ['ACCESSIBILITY_SUMMARY_MISSING']];
        yield 'none at all' => [
            ['accessMode', 'accessibilityFeature', 'accessibilityHazard', 'accessibilitySummary'],
            ['ACCESSIBILITY_ACCESS_MODE_MISSING', 'ACCESSIBILITY_FEATURE_MISSING', 'ACCESSIBILITY_HAZARD_MISSING', 'ACCESSIBILITY_SUMMARY_MISSING'],
        ];
    }

    public function testEmptyAccessibilityValuesCountAsMissing(): void
    {
        $opf = str_replace('>none</meta>', '> </meta>', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));

        $this->assertSame(['ACCESSIBILITY_HAZARD_MISSING'], $this->codes($this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf))->validate()));
    }

    public function testEpub2BooksNeedNoAccessibilityMetadata(): void
    {
        $this->assertSame([], $this->open(EpubBuilder::epub2())->validate());
    }

    public function testEpub2BooksNeedAnNcxNotANavigationDocument(): void
    {
        $opf = str_replace(
            ['version="3.0"', ' properties="nav"', '<meta property="dcterms:modified">2026-01-01T00:00:00Z</meta>'],
            ['version="2.0"', '', ''],
            (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
        );

        $issues = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf))->validate();

        $this->assertSame(['NCX_MISSING'], $this->codes($issues));
        $this->assertSame(ValidationIssue::ERROR, $issues[0]->severity);
    }

    public function testADanglingTableOfContentsLinkIsAnError(): void
    {
        $book = EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml(
            'Contents',
            '<nav epub:type="toc"><ol><li><a href="text/chapter.xhtml">Chapter</a></li><li><a href="text/missing.xhtml">Missing</a></li></ol></nav>'
        ));

        $issues = $this->open($book)->validate();

        $this->assertSame(['TOC_LINK_NOT_IN_MANIFEST'], $this->codes($issues));
        // EPUBCheck rejects such a book (RSC-007).
        $this->assertSame(ValidationIssue::ERROR, $issues[0]->severity);
    }

    public function testIssuesDescribeTheProblemAndWhere(): void
    {
        $opf = str_replace('</manifest>', '<item id="gone" href="gone.png" media-type="image/png"/></manifest>', (string) EpubBuilder::epub3()->getFile('EPUB/package.opf'));

        $issue = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf))->validate()[0];

        $this->assertSame(ValidationIssue::ERROR, $issue->severity);
        $this->assertSame('EPUB/gone.png', $issue->location);
        $this->assertStringContainsString('gone', $issue->message);
        $this->assertSame('error MANIFEST_FILE_MISSING (EPUB/gone.png): ' . $issue->message, (string) $issue);
    }

    public function testValidateBeforeLoadThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('EPUB file must be loaded before validating.');

        (new EpubFile($this->tmpDir . '/missing.epub'))->validate();
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
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
}
