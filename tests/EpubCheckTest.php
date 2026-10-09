<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Build\BookBuilder;
use PhpEpub\Build\BookOptions;
use PhpEpub\Build\BuiltBook;
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
        $this->assertSavedBookIsValid(EpubBuilder::epub3(), $edit);
    }

    /**
     * EPUB 2 books exercise the NCX, opf:* attributes and the guide, which EPUB 3 books do not.
     *
     * @param \Closure(EpubFile): void $edit
     */
    #[DataProvider('epub2Edits')]
    public function testSavedEpub2BookIsValid(\Closure $edit): void
    {
        $this->assertSavedBookIsValid(EpubBuilder::epub2(), $edit);
    }

    /**
     * @param \Closure(EpubFile): void $edit
     */
    private function assertSavedBookIsValid(EpubBuilder $book, \Closure $edit): void
    {
        $source = $book->buildEpub($this->tmpDir . DIRECTORY_SEPARATOR . 'source.epub');
        $saved = $this->tmpDir . DIRECTORY_SEPARATOR . 'saved.epub';

        $epubFile = EpubFile::open($source);
        $edit($epubFile);
        $epubFile->save($saved);
        $epubFile->cleanup();

        // The library reads its own output back, and finds nothing wrong with it.
        $reopened = EpubFile::open($saved);
        $this->assertNotSame('', $reopened->getMetadata()->getTitle());
        $this->assertSame([], array_map(strval(...), $reopened->validate()));
        $reopened->cleanup();

        $this->assertPassesEpubCheck($saved);
    }

    /**
     * Real-world books need not be valid, but saving them must not add problems: the codes that
     * validate() and EPUBCheck report for the saved book are a subset of those for the original.
     */
    #[DataProvider('fixtureBooks')]
    public function testSavingAFixtureBookAddsNoProblems(string $fixture): void
    {
        $saved = $this->tmpDir . DIRECTORY_SEPARATOR . 'saved.epub';

        $original = EpubFile::open($fixture);
        $before = self::codes($original->validate());
        $original->getMetadata()->setTitle($original->getMetadata()->getTitle() . ' (saved)');
        $original->save($saved);
        $original->cleanup();

        $reopened = EpubFile::open($saved);
        $after = self::codes($reopened->validate());
        $reopened->cleanup();

        $this->assertSame([], array_values(array_diff($after, $before)), 'validate() reports new problems after saving.');
        $this->assertSame([], array_values(array_diff($this->epubCheckCodes($saved), $this->epubCheckCodes($fixture))), 'EPUBCheck reports new problems after saving.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtureBooks(): iterable
    {
        foreach (glob(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid*.epub') ?: [] as $fixture) {
            yield basename($fixture) => [$fixture];
        }
    }

    /**
     * @param list<\PhpEpub\ValidationIssue> $issues
     *
     * @return list<string>
     */
    private static function codes(array $issues): array
    {
        return array_values(array_unique(array_map(static fn (\PhpEpub\ValidationIssue $issue): string => $issue->code, $issues)));
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
     * Books made by BookBuilder from Markdown, text, HTML and images must be valid, reflowable and fixed-layout alike.
     *
     * @param \Closure(): BuiltBook $build
     */
    #[DataProvider('builtBooks')]
    public function testBuiltBookIsValid(\Closure $build): void
    {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'built.epub';
        $build()->save($path);

        $reopened = EpubFile::open($path);
        $this->assertSame([], array_map(strval(...), $reopened->validate()));
        $reopened->cleanup();

        $this->assertPassesEpubCheck($path);
    }

    /**
     * @return iterable<string, array{\Closure(): BuiltBook}>
     */
    public static function builtBooks(): iterable
    {
        $png = (string) base64_decode(EpubBuilder::PNG, true);
        $jpeg = (string) base64_decode(EpubBuilder::JPEG, true);
        $options = new BookOptions(
            title: 'Built Book',
            authors: ['Ann Author'],
            language: 'en',
            description: 'Made by BookBuilder',
            publisher: 'Publisher',
            date: '2026-10-09',
            coverImage: $png,
            css: 'p { text-indent: 1em }',
            images: ['images/dot.png' => $png],
            splitLevel: 2
        );
        $markdown = "Front matter.\n\n# One\n\nText with *emphasis*, a [link](http://example.com), [a jump](#two) and ![dot](images/dot.png).\n\n"
            . "## Two\n\n- a\n- b\n\n> quote\n\n```\ncode\n```\n\n| h | i |\n|---|---|\n| 1 | 2 |\n";

        yield 'markdown' => [static fn (): BuiltBook => (new BookBuilder($options))->fromMarkdown($markdown)];
        yield 'html' => [static fn (): BuiltBook => (new BookBuilder($options))->fromHtml('<h1>One</h1><p id="a">Text <a href="#b">jump</a></p><h1>Two</h1><p id="b">More <img src="dot.png" alt="dot"/></p><table><tr><td>x</td></tr></table>')];
        yield 'text' => [static fn (): BuiltBook => (new BookBuilder(new BookOptions(title: 'Plain', language: 'de')))->fromText("Chapter 1\n\nHello.\n\nChapter 2\n\nWorld.\n")];
        yield 'images' => [static fn (): BuiltBook => (new BookBuilder(new BookOptions(title: 'Comic', direction: 'rtl', language: 'ja')))->fromImages([
            ['name' => '1.png', 'bytes' => $png],
            ['name' => '2.jpg', 'bytes' => $jpeg],
        ])];
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
            $metadata->setSeries('A Series', 2);
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

        yield 'chapter with SVG, MathML and a script' => [static function (EpubFile $epubFile): void {
            $epubFile->addChapter('Rich', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="5" height="5"/></svg>'
                . '<math xmlns="http://www.w3.org/1998/Math/MathML"><mi>x</mi></math><script>var x = 1;</script>');
        }];

        yield 'chapter deleted after being added to the table of contents' => [static function (EpubFile $epubFile): void {
            $chapter = $epubFile->addChapter('Short-lived', '<p>Gone soon.</p>');
            $epubFile->getContentManager()->deleteContent($chapter->path);
        }];

        yield 'cover image' => [static function (EpubFile $epubFile): void {
            $epubFile->setCoverImage((string) base64_decode(EpubBuilder::PNG, true), 'image/png');
        }];

        yield 'cover image deleted again' => [static function (EpubFile $epubFile): void {
            $cover = $epubFile->setCoverImage((string) base64_decode(EpubBuilder::PNG, true), 'image/png');
            $epubFile->getContentManager()->deleteContent($cover->path);
        }];
    }

    /**
     * @return iterable<string, array{\Closure(EpubFile): void}>
     */
    public static function epub2Edits(): iterable
    {
        yield 'unchanged' => [static function (EpubFile $epubFile): void {
        }];

        yield 'metadata' => [static function (EpubFile $epubFile): void {
            $metadata = $epubFile->getMetadata();
            $metadata->setTitle('Edited EPUB 2 & <Title>');
            $metadata->setAuthors(['Ann Author']);
            $metadata->addCreator('Ivan Illustrator', 'ill', 'Illustrator, Ivan');
            $metadata->addContributor('Ed Editor', 'edt', 'Editor, Ed');
            $metadata->setIdentifiers(['urn:uuid:9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d', 'urn:isbn:9780000000002']);
            $metadata->setDate('2026-10-07');
            $metadata->setSeries('A Series', 2);
            $metadata->setDublinCoreValues('rights', ['CC BY 4.0']);
        }];

        yield 'chapters and table of contents' => [static function (EpubFile $epubFile): void {
            $added = $epubFile->addChapter('Added', '<h1>Added</h1><h2 id="section">Section</h2><p>New.</p>');
            $epubFile->getTableOfContents()->addEntry(new TocEntry('Added section', $added->path, 'section'));
        }];

        yield 'chapter in the guide deleted' => [static function (EpubFile $epubFile): void {
            $epubFile->addChapter('Second', '<p>Second chapter.</p>');
            $epubFile->getContentManager()->deleteContent('OEBPS/text/chapter.xhtml');
        }];

        yield 'cover image' => [static function (EpubFile $epubFile): void {
            $epubFile->setCoverImage((string) base64_decode(EpubBuilder::PNG, true), 'image/png');
        }];

        yield 'upgraded to EPUB 3' => [static function (EpubFile $epubFile): void {
            $epubFile->upgradeToEpub3();
            $metadata = $epubFile->getMetadata();
            $metadata->setAccessModes(['textual']);
            $metadata->setAccessibilityFeatures(['tableOfContents']);
            $metadata->setAccessibilityHazards(['none']);
            $metadata->setAccessibilitySummary('Plain text with a table of contents.');
        }];
    }

    private function assertPassesEpubCheck(string $epubPath): void
    {
        $result = $this->runEpubCheck($epubPath);
        if ($result === null) {
            return;
        }

        $this->assertSame(0, $result['exitCode'], "EPUBCheck reported errors:\n" . implode("\n", $result['output']));
    }

    /**
     * The codes of the errors EPUBCheck reports (e.g. "RSC-005"); [] when EPUBCheck is not set up.
     *
     * @return list<string>
     */
    private function epubCheckCodes(string $epubPath): array
    {
        $result = $this->runEpubCheck($epubPath);
        if ($result === null) {
            return [];
        }

        preg_match_all('/^(?:ERROR|FATAL)\(([A-Z]+-\d+)\)/m', implode("\n", $result['output']), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Runs EPUBCheck when EPUBCHECK_JAR is set; null otherwise.
     *
     * @return array{exitCode: int, output: list<string>}|null
     */
    private function runEpubCheck(string $epubPath): ?array
    {
        $jar = getenv('EPUBCHECK_JAR');
        if ($jar === false || $jar === '') {
            return null;
        }

        $java = getenv('EPUBCHECK_JAVA');
        $command = escapeshellarg($java === false || $java === '' ? 'java' : $java)
            . ' -jar ' . escapeshellarg($jar) . ' ' . escapeshellarg($epubPath) . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return ['exitCode' => $exitCode, 'output' => $output];
    }
}
