<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\TocEntry;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Books edited and saved by the library must stay valid.
 *
 * Every scenario saves a book and reopens it. When EPUBCHECK_JAR points at
 * epubcheck.jar (CI sets it up), the saved book is also checked with the
 * reference validator; EPUBCHECK_JAVA overrides the java binary.
 */
#[Group('epubcheck')]
final class EpubCheckTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'epubcheck';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    /**
     * @param \Closure(EpubFile): void $edit
     */
    #[DataProvider('edits')]
    public function testSavedBookIsValid(\Closure $edit): void
    {
        $source = EpubBuilder::epub3()->buildEpub($this->tmpDir . DIRECTORY_SEPARATOR . 'source.epub');
        $saved = $this->tmpDir . DIRECTORY_SEPARATOR . 'saved.epub';

        $epubFile = EpubFile::open($source);
        $edit($epubFile);
        $epubFile->save($saved);
        $epubFile->cleanup();

        // The library reads its own output back.
        $reopened = EpubFile::open($saved);
        $this->assertNotSame('', $reopened->getMetadata()->getTitle());
        $reopened->cleanup();

        $this->assertPassesEpubCheck($saved);
    }

    public function testBookCreatedFromScratchIsValid(): void
    {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'created.epub';

        $epubFile = EpubFile::create($path, 'Created From Scratch', 'en');
        $epubFile->getMetadata()->setAuthors(['Ann Author']);
        $epubFile->addChapter('Chapter One', '<h1>Chapter One</h1><p>First.</p>');
        $epubFile->addChapter('Chapter Two', '<h1>Chapter Two</h1><p>Second.</p>');
        $epubFile->setCoverImage((string) base64_decode(EpubBuilder::PNG, true), 'image/png');
        $epubFile->save();
        $epubFile->cleanup();

        $reopened = EpubFile::open($path);
        $this->assertCount(2, $reopened->getTableOfContents()->getEntries());
        $reopened->cleanup();

        $this->assertPassesEpubCheck($path);
    }

    /**
     * @return iterable<string, array{\Closure(EpubFile): void}>
     */
    public static function edits(): iterable
    {
        yield 'unchanged' => [static function (EpubFile $epubFile): void {
        }];

        yield 'metadata' => [static function (EpubFile $epubFile): void {
            $metadata = $epubFile->getMetadata();
            $metadata->setTitle('Edited & Saved <Title>');
            $metadata->setAuthors(['Ann Author', 'Bob Writer']);
            $metadata->setDescription('A description with Ünïcødé.');
            $metadata->setPublisher('Publisher');
            $metadata->setDate('2026-10-06');
            $metadata->setSubjects(['Fiction', 'Testing']);
            $metadata->setIdentifiers(['urn:uuid:9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d', 'urn:isbn:9780000000002']);
            $metadata->setMeta('calibre:series', 'Series');
            $metadata->setProperty('belongs-to-collection', 'Collection');
            $metadata->setPropertyValues('dcterms:subject', ['Subject A', 'Subject B']);
            $metadata->setTitles(['Main Title', 'A Subtitle']);
            $metadata->addCreator('Ivan Illustrator', 'ill', 'Illustrator, Ivan');
            $metadata->addContributor('Ed Editor', 'edt', 'Editor, Ed');
        }];

        yield 'added and removed content' => [static function (EpubFile $epubFile): void {
            $content = $epubFile->getContentManager();
            $content->addContent('EPUB/text/added.xhtml', EpubBuilder::xhtml('Added', '<h1>Added</h1><p>New chapter.</p><h2 id="section">Section</h2>'));
            $epubFile->getSpine()->add('added-xhtml');
            $epubFile->getTableOfContents()->addEntry(new TocEntry('Added', 'EPUB/text/added.xhtml', null, [
                new TocEntry('Added section', 'EPUB/text/added.xhtml', 'section'),
            ]));
            $content->addContent('EPUB/css/extra.css', 'h1 { color: black; }');
            $content->deleteContent('EPUB/css/extra.css');
        }];

        yield 'cover image' => [static function (EpubFile $epubFile): void {
            $epubFile->setCoverImage((string) base64_decode(EpubBuilder::PNG, true), 'image/png');
        }];

        yield 'cover image deleted again' => [static function (EpubFile $epubFile): void {
            $cover = $epubFile->setCoverImage((string) base64_decode(EpubBuilder::PNG, true), 'image/png');
            $epubFile->getContentManager()->deleteContent($cover->path);
        }];
    }

    private function assertPassesEpubCheck(string $epubPath): void
    {
        $jar = getenv('EPUBCHECK_JAR');
        if ($jar === false || $jar === '') {
            return;
        }

        $java = getenv('EPUBCHECK_JAVA');
        $command = escapeshellarg($java === false || $java === '' ? 'java' : $java)
            . ' -jar ' . escapeshellarg($jar) . ' ' . escapeshellarg($epubPath) . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, "EPUBCheck reported errors:\n" . implode("\n", $output));
    }
}
