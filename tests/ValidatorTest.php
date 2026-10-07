<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ValidationIssue;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testABookCreatedFromScratchIsValidOnceItHasAChapter(): void
    {
        $epubFile = EpubFile::create($this->tmpDir . '/new.epub', 'New');
        $this->assertSame(['SPINE_EMPTY'], $this->codes($epubFile->validate()));

        $epubFile->addChapter('One', '<p>One</p>');

        $this->assertSame([], $epubFile->validate());
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
