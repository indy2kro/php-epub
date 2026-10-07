<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\TocEntry;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

final class CreateEpubTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'create';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testCreateWithChaptersSavesABookThatReopens(): void
    {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'new.epub';

        $epubFile = EpubFile::create($path, 'A New Book', 'fr');
        $first = $epubFile->addChapter('Chapter One', '<h1>Chapter One</h1><p>Text.</p>');
        $second = $epubFile->addChapter('Café & <More>', '<p>Second.</p>');
        $epubFile->getMetadata()->setAuthors(['Ann Author']);
        $epubFile->save();
        $this->assertFileDoesNotExist($this->tmpDir . DIRECTORY_SEPARATOR . 'missing');

        $reopened = EpubFile::open($path);
        $metadata = $reopened->getMetadata();
        $this->assertSame('A New Book', $metadata->getTitle());
        $this->assertSame('fr', $metadata->getLanguage());
        $this->assertSame(['Ann Author'], $metadata->getAuthors());
        $this->assertSame('3.0', $metadata->getVersion());
        $this->assertMatchesRegularExpression('/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $metadata->getIdentifiers()[0]);
        $this->assertNotNull($metadata->getModifiedDate());

        $this->assertSame(['EPUB/text/chapter-1.xhtml', 'EPUB/text/chapter-2.xhtml'], [$first->path, $second->path]);
        $this->assertSame([$first->id, $second->id], $reopened->getSpine()->get());
        $this->assertEquals(
            [new TocEntry('Chapter One', 'EPUB/text/chapter-1.xhtml'), new TocEntry('Café & <More>', 'EPUB/text/chapter-2.xhtml')],
            $reopened->getTableOfContents()->getEntries()
        );

        $chapter = $reopened->getContentManager()->getContent('EPUB/text/chapter-2.xhtml');
        $this->assertStringContainsString('<title>Café &amp; &lt;More&gt;</title>', $chapter);
        $this->assertStringContainsString('xml:lang="fr"', $chapter);
        $this->assertStringContainsString('<body><p>Second.</p></body>', $chapter);
    }

    public function testCreateWithAGivenIdentifier(): void
    {
        $epubFile = EpubFile::create($this->tmpDir . DIRECTORY_SEPARATOR . 'id.epub', 'Title', 'en', 'urn:isbn:9780000000002');

        $this->assertSame(['urn:isbn:9780000000002'], $epubFile->getMetadata()->getIdentifiers());
    }

    public function testCreateRejectsEmptyOrInvalidValues(): void
    {
        foreach (['title' => ['', 'en'], 'language' => ['Title', ' '], 'control character' => ["Bad\x01", 'en']] as $label => [$title, $language]) {
            try {
                EpubFile::create($this->tmpDir . DIRECTORY_SEPARATOR . 'bad.epub', $title, $language);
                $this->fail("Expected an exception for an invalid {$label}.");
            } catch (Exception) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAddChapterToAnExistingEpub2BookUsesItsNcxAndADefaultPathNextToTheOpf(): void
    {
        $opf = '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="uid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="uid">urn:x</dc:identifier><dc:title>Old</dc:title><dc:language>de</dc:language></metadata>'
            . '<manifest><item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/><item id="c" href="chapter-1.xhtml" media-type="application/xhtml+xml"/></manifest>'
            . '<spine toc="ncx"><itemref idref="c"/></spine></package>';
        $ncx = '<?xml version="1.0" encoding="UTF-8"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head/><docTitle><text>Old</text></docTitle>'
            . '<navMap><navPoint id="p1" playOrder="1"><navLabel><text>One</text></navLabel><content src="chapter-1.xhtml"/></navPoint></navMap></ncx>';
        $source = (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('content.opf')
            ->withFile('content.opf', $opf)
            ->withFile('toc.ncx', $ncx)
            ->withFile('chapter-1.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>1</title></head><body><p>1</p></body></html>')
            ->buildEpub($this->tmpDir . DIRECTORY_SEPARATOR . 'old.epub');
        $epubFile = EpubFile::open($source);

        $item = $epubFile->addChapter('Two', '<p>2</p>');

        // New chapters go in text/ next to the OPF.
        $this->assertSame('text/chapter-1.xhtml', $epubFile->getManifest()->pathToHref($item->path));
        $this->assertSame(['c', $item->id], $epubFile->getSpine()->get());
        $titles = array_map(static fn (TocEntry $entry): string => $entry->title, $epubFile->getTableOfContents()->getEntries());
        $this->assertSame(['One', 'Two'], $titles);
        $this->assertStringContainsString('xml:lang="de"', $epubFile->getContentManager()->getContent($item->path));
    }

    public function testAddChapterToABookWithoutNavigationSkipsTheTableOfContents(): void
    {
        $epubFile = EpubFile::open(EpubBuilder::minimal()->buildEpub($this->tmpDir . DIRECTORY_SEPARATOR . 'minimal.epub'));

        $item = $epubFile->addChapter('Extra', '<p>Extra</p>', 'EPUB/extra/page.xhtml');

        $this->assertSame('EPUB/extra/page.xhtml', $item->path);
        $this->assertSame(['chapter', $item->id], $epubFile->getSpine()->get());
        $this->assertSame([], $epubFile->getTableOfContents()->getEntries());
    }

    public function testAddChapterRejectsInvalidText(): void
    {
        $epubFile = EpubFile::create($this->tmpDir . DIRECTORY_SEPARATOR . 'x.epub', 'Title');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not valid XML text');

        $epubFile->addChapter("Bad\x01", '<p>x</p>');
    }
}
