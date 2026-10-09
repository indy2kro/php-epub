<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\BookSummary;
use PhpEpub\Contributor;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\WordCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type SummaryShape from BookSummary
 */
final class BookSummaryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'summary';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (glob(__DIR__ . '/fixtures/valid*.epub') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('fixtures')]
    public function testFixtureHasTheDocumentedShape(string $path): void
    {
        $summary = EpubFile::open($path)->toArray();

        $this->assertShape($summary);
        $this->assertJsonRoundTrip($summary);
        $this->assertNotSame([], $summary['spine']);
    }

    public function testEpub3Book(): void
    {
        $summary = $this->open(EpubBuilder::epub3())->toArray();

        $this->assertShape($summary);
        $this->assertJsonRoundTrip($summary);
        $this->assertSame('3.0', $summary['version']);
        $this->assertSame(['Valid Book'], $summary['metadata']['titles']);
        $this->assertSame('2026-01-01T00:00:00Z', $summary['metadata']['dates']['modified']);
        $this->assertSame(['textual'], $summary['metadata']['accessibility']['accessModes']);
        $this->assertSame('urn:uuid:3f1e2a4c-5b6d-4e7f-8a9b-0c1d2e3f4a5b', $summary['metadata']['identifiers']['unique']);
        $this->assertNull($summary['cover']);
        $this->assertSame('Chapter', $summary['toc']['entries'][0]['title']);
        $this->assertSame([], $summary['toc']['entries'][0]['children']);
        $this->assertSame(
            [['idref' => 'chapter', 'path' => 'EPUB/text/chapter.xhtml', 'linear' => true, 'title' => 'Chapter', 'bytes' => strlen((string) EpubBuilder::epub3()->getFile('EPUB/text/chapter.xhtml'))]],
            $summary['spine']
        );
        $this->assertSame(['count' => 2, 'bytes' => $summary['stats']['groups']['xhtml']['bytes']], $summary['stats']['groups']['xhtml']);
        $this->assertSame(['count' => 1, 'bytes' => 16], $summary['stats']['groups']['css']);
        $this->assertSame(2, $summary['stats']['wordCount']);
        $this->assertSame(1, $summary['stats']['readingMinutes']);
        $this->assertSame(['isDrmProtected' => false, 'encryptedPathCount' => 0], $summary['drm']);
    }

    public function testEpub2Book(): void
    {
        $summary = $this->open(EpubBuilder::epub2())->toArray();

        $this->assertShape($summary);
        $this->assertJsonRoundTrip($summary);
        $this->assertSame('2.0', $summary['version']);
        $this->assertSame([['name' => 'Ann Author', 'role' => 'aut', 'fileAs' => 'Author, Ann']], $summary['metadata']['creators']);
        $this->assertSame(['modification' => '2026-01-01'], $summary['metadata']['dates']['events']);
        $this->assertSame('Chapter', $summary['spine'][0]['title']);
        $this->assertSame('bodymatter', $summary['toc']['landmarks'][0]['type']);
        $this->assertSame('UUID', $summary['metadata']['identifiers']['all'][0]['scheme']);
    }

    public function testMinimalBookWithoutOptionalParts(): void
    {
        $summary = $this->open(EpubBuilder::minimal())->toArray();

        $this->assertShape($summary);
        $this->assertJsonRoundTrip($summary);
        $this->assertNull($summary['cover']);
        $this->assertSame([], $summary['toc']['entries']);
        $this->assertSame([], $summary['toc']['landmarks']);
        $this->assertSame(0, $summary['toc']['pageListCount']);
        $this->assertNull($summary['spine'][0]['title']);
        $this->assertNull($summary['metadata']['description']);
        $this->assertNull($summary['metadata']['publisher']);
        $this->assertNull($summary['metadata']['rendition']['layout']);
        $this->assertNull($summary['metadata']['series']['name']);
        $this->assertSame([], $summary['metadata']['rights']);
    }

    public function testIncludesUnsavedEdits(): void
    {
        $epubFile = $this->open(EpubBuilder::epub3());
        $metadata = $epubFile->getMetadata();
        $metadata->setTitle('Edited');
        $metadata->setCreators([new Contributor('Jane Doe', 'aut', 'Doe, Jane')]);
        $metadata->setSeries('Saga', 2);
        $metadata->setRenditionLayout('pre-paginated');
        $epubFile->addChapter('Added', '<p>One two three</p>');

        $summary = $epubFile->toArray();

        $this->assertSame(['Edited'], $summary['metadata']['titles']);
        $this->assertSame('Jane Doe', $summary['metadata']['creators'][0]['name']);
        $this->assertSame(['name' => 'Saga', 'index' => '2'], $summary['metadata']['series']);
        $this->assertSame('pre-paginated', $summary['metadata']['rendition']['layout']);
        $this->assertCount(2, $summary['spine']);
        $this->assertSame('Added', $summary['spine'][1]['title']);
        $this->assertSame(2 + 3, $summary['stats']['wordCount']);
        $this->assertJsonRoundTrip($summary);
    }

    public function testCoverAndMediaGroups(): void
    {
        $png = (string) base64_decode(EpubBuilder::PNG, true);
        $opf = str_replace(
            '<item id="style"',
            '<item id="cover" href="images/cover.png" media-type="image/png" properties="cover-image"/>'
            . '<item id="font" href="fonts/a.woff2" media-type="font/woff2"/>'
            . '<item id="audio" href="audio/a.mp3" media-type="audio/mpeg"/>'
            . '<item id="data" href="data.bin" media-type="application/octet-stream"/><item id="style"',
            (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
        );
        $book = EpubBuilder::epub3()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/images/cover.png', $png)
            ->withFile('EPUB/fonts/a.woff2', 'font')
            ->withFile('EPUB/audio/a.mp3', 'audio!')
            ->withFile('EPUB/data.bin', 'xyz');

        $summary = $this->open($book)->toArray();

        $this->assertSame(['path' => 'EPUB/images/cover.png', 'mediaType' => 'image/png', 'bytes' => strlen($png)], $summary['cover']);
        $this->assertSame(['count' => 1, 'bytes' => strlen($png)], $summary['stats']['groups']['images']);
        $this->assertSame(['count' => 1, 'bytes' => 4], $summary['stats']['groups']['fonts']);
        $this->assertSame(['count' => 1, 'bytes' => 6], $summary['stats']['groups']['media']);
        $this->assertSame(['count' => 1, 'bytes' => 3], $summary['stats']['groups']['other']);
        $this->assertGreaterThan($summary['stats']['groups']['images']['bytes'], $summary['stats']['totalBytes']);
        $this->assertJsonRoundTrip($summary);
    }

    public function testNestedTocAndFragments(): void
    {
        $nav = EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><ol>'
            . '<li><a href="text/chapter.xhtml">Part</a><ol><li><a href="text/chapter.xhtml#s1">Section</a></li></ol></li>'
            . '</ol></nav>');
        $summary = $this->open(EpubBuilder::epub3()->withFile('EPUB/nav.xhtml', $nav))->toArray();

        $this->assertSame('Part', $summary['toc']['entries'][0]['title']);
        $this->assertSame(
            [['title' => 'Section', 'path' => 'EPUB/text/chapter.xhtml', 'fragment' => 's1', 'children' => []]],
            $summary['toc']['entries'][0]['children']
        );
        $this->assertSame('Part', $summary['spine'][0]['title']);
    }

    public function testDrmBookDoesNotCountEncryptedDocuments(): void
    {
        $encryption = '<?xml version="1.0"?><encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#">'
            . '<enc:EncryptedData><enc:EncryptionMethod Algorithm="http://www.w3.org/2001/04/xmlenc#aes128-cbc"/>'
            . '<enc:CipherData><enc:CipherReference URI="EPUB/text/chapter.xhtml"/></enc:CipherData></enc:EncryptedData></encryption>';

        $summary = $this->open(EpubBuilder::epub3()->withFile('META-INF/encryption.xml', $encryption))->toArray();

        $this->assertSame(['isDrmProtected' => true, 'encryptedPathCount' => 1], $summary['drm']);
        $this->assertSame(0, $summary['stats']['wordCount']);
        $this->assertJsonRoundTrip($summary);
    }

    public function testHostileHrefsStillEncodeAsJson(): void
    {
        $opf = str_replace(
            ['<item id="style"', '<itemref idref="chapter"/>', '</package>'],
            [
                '<item id="bad" href="x%FF.xhtml" media-type="application/xhtml+xml"/>'
                . '<item id="cover" href="c%FF.png" media-type="image/png" properties="cover-image"/><item id="style"',
                '<itemref idref="chapter"/><itemref idref="bad"/>',
                '<guide><reference type="cover" title="Cover" href="text/chapter.xhtml#%C3"/></guide></package>',
            ],
            (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
        );
        $nav = EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><ol>'
            . '<li><a href="text/chapter.xhtml#%FF">Chapter</a></li><li><a href="x%FF.xhtml">Bad</a></li></ol></nav>');

        $summary = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf)->withFile('EPUB/nav.xhtml', $nav))->toArray();

        $this->assertJson(json_encode($summary, JSON_THROW_ON_ERROR));
        $this->assertSame('bad', $summary['spine'][1]['idref']);
        $this->assertTrue(mb_check_encoding($summary['spine'][1]['path'], 'UTF-8'));
        $this->assertSame(0, $summary['spine'][1]['bytes']);
        $this->assertSame('Bad', $summary['spine'][1]['title']);
        $this->assertTrue(mb_check_encoding((string) $summary['toc']['entries'][0]['fragment'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding((string) $summary['toc']['landmarks'][0]['fragment'], 'UTF-8'));
        $this->assertIsArray($summary['cover']);
        $this->assertTrue(mb_check_encoding($summary['cover']['path'], 'UTF-8'));
    }

    public function testMissingDocumentIsSkippedInTheWordCount(): void
    {
        $opf = str_replace(
            ['<item id="style"', '<itemref idref="chapter"/>'],
            ['<item id="gone" href="text/gone.xhtml" media-type="application/xhtml+xml"/><item id="style"', '<itemref idref="gone"/><itemref idref="chapter"/>'],
            (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
        );

        $summary = $this->open(EpubBuilder::epub3()->withFile('EPUB/package.opf', $opf))->toArray();

        $this->assertSame(2, $summary['stats']['wordCount']);
        $this->assertSame(0, $summary['spine'][0]['bytes']);
    }

    public function testThrowsWhenNotLoaded(): void
    {
        $this->expectException(Exception::class);
        (new EpubFile(__DIR__ . '/fixtures/valid.epub'))->toArray();
    }

    #[DataProvider('wordCounts')]
    public function testWordCount(string $text, int $expected): void
    {
        $this->assertSame($expected, WordCount::count($text));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function wordCounts(): iterable
    {
        yield 'empty' => ['', 0];
        yield 'white space only' => [" \n\t ", 0];
        yield 'plain words' => ["Chapter One\nThe end.", 4];
        yield 'punctuation is not a word' => ['Hello, world! — ... ?', 2];
        yield 'apostrophes and hyphens join' => ["Don't stop the well-known l\u{2019}homme", 5];
        yield 'numbers' => ['In 1984 there were 3.5 million', 6];
        yield 'accented and Cyrillic' => ['Café déjà vu Привет, мир', 5];
        yield 'Greek and Hangul' => ['Καλημέρα κόσμε 안녕하세요 세계', 4];
        yield 'Chinese counts characters' => ['你好，世界！', 4];
        yield 'Japanese counts characters' => ['私は猫です。カタカナ', 9];
        yield 'mixed CJK and Latin' => ['Hello 世界 and 東京 city', 7];
        yield 'invalid UTF-8' => ["bad \xFF\xFE bytes", 0];
    }

    public function testReadingMinutesRoundUp(): void
    {
        $this->assertSame(0, BookSummary::readingMinutes(0));
        $this->assertSame(1, BookSummary::readingMinutes(1));
        $this->assertSame(1, BookSummary::readingMinutes(230));
        $this->assertSame(2, BookSummary::readingMinutes(231));
    }

    /**
     * @param SummaryShape $summary
     */
    private function assertShape(array $summary): void
    {
        $this->assertSame(['version', 'metadata', 'cover', 'toc', 'spine', 'stats', 'drm'], array_keys($summary));
        $this->assertSame(
            ['titles', 'creators', 'contributors', 'subjects', 'description', 'publisher', 'languages', 'rights', 'dates', 'identifiers', 'series', 'accessibility', 'rendition'],
            array_keys($summary['metadata'])
        );
        $this->assertSame(['published', 'modified', 'events'], array_keys($summary['metadata']['dates']));
        $this->assertSame(['all', 'unique', 'isbn'], array_keys($summary['metadata']['identifiers']));
        $this->assertSame(['layout', 'orientation', 'spread', 'flow', 'pageProgressionDirection'], array_keys($summary['metadata']['rendition']));
        $this->assertSame(['entries', 'landmarks', 'pageListCount'], array_keys($summary['toc']));
        $this->assertSame(['groups', 'totalBytes', 'wordCount', 'readingMinutes'], array_keys($summary['stats']));
        $this->assertSame(['xhtml', 'css', 'images', 'fonts', 'media', 'other'], array_keys($summary['stats']['groups']));
        $this->assertSame(['isDrmProtected', 'encryptedPathCount'], array_keys($summary['drm']));
        $this->assertGreaterThan(0, $summary['stats']['totalBytes']);
        foreach ($summary['spine'] as $item) {
            $this->assertSame(['idref', 'path', 'linear', 'title', 'bytes'], array_keys($item));
        }
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function assertJsonRoundTrip(array $summary): void
    {
        $json = json_encode($summary, JSON_THROW_ON_ERROR);
        $this->assertSame($summary, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/book-' . bin2hex(random_bytes(4)) . '.epub'));
    }
}
