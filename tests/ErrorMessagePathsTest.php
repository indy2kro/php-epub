<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\FontObfuscation;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ValidationIssue;
use PHPUnit\Framework\TestCase;

/**
 * Messages about a book name its book-relative paths, never the private extraction directory.
 */
final class ErrorMessagePathsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'error-paths';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testAMissingContainerIsNamedRelativeToTheBook(): void
    {
        $message = $this->openFailure(EpubBuilder::epub3()->withoutFile('META-INF/container.xml'));

        $this->assertStringContainsString('META-INF/container.xml', $message);
        $this->assertNoExtractionPath($message);
    }

    public function testABrokenPackageDocumentIsNamedRelativeToTheBook(): void
    {
        $message = $this->openFailure(EpubBuilder::epub3()->withFile('EPUB/package.opf', '<package'));

        $this->assertStringContainsString('EPUB/package.opf', $message);
        $this->assertNoExtractionPath($message);
    }

    public function testContentManagerNamesTheBookPath(): void
    {
        $content = EpubFile::open(EpubBuilder::epub3()->buildEpub($this->tmpDir . '/book.epub'))->getContentManager();

        $calls = [
            static fn () => $content->getContent('EPUB/text/missing.xhtml'),
            static fn () => $content->updateContent('EPUB/text/missing.xhtml', 'x'),
            static fn () => $content->deleteContent('EPUB/text/missing.xhtml'),
            static fn () => $content->moveContent('EPUB/text/missing.xhtml', 'EPUB/text/other.xhtml'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('No exception was thrown.');
            } catch (Exception $exception) {
                $this->assertStringContainsString('EPUB/text/missing.xhtml', $exception->getMessage());
                $this->assertNoExtractionPath($exception->getMessage());
            }
        }
    }

    public function testValidationIssuesNameTheBookPath(): void
    {
        $book = EpubBuilder::epub3()->withFile('EPUB/text/chapter.xhtml', '<html><p>broken');
        $issues = EpubFile::open($book->buildEpub($this->tmpDir . '/broken.epub'))->validate();

        $messages = array_map(static fn (ValidationIssue $issue): string => (string) $issue, $issues);
        $this->assertNotEmpty(array_filter($messages, static fn (string $message): bool => str_contains($message, 'CONTENT_NOT_WELL_FORMED')));
        foreach ($messages as $message) {
            $this->assertNoExtractionPath($message);
        }
    }

    public function testAnUnreadableEncryptionXmlIsNamedRelativeToTheBook(): void
    {
        $root = $this->tmpDir . DIRECTORY_SEPARATOR . 'extracted';
        mkdir($root . DIRECTORY_SEPARATOR . 'META-INF', 0777, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'encryption.xml', '<encryption');

        try {
            (new FontObfuscation($root))->setAlgorithm('EPUB/fonts/font.ttf', FontObfuscation::IDPF);
            $this->fail('No exception was thrown.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('META-INF/encryption.xml', $exception->getMessage());
            $this->assertStringNotContainsString($root, $exception->getMessage());
        }
    }

    public function testAnEncryptionXmlThatCannotBeSavedIsNamedRelativeToTheBook(): void
    {
        // A directory where encryption.xml should be: the file is missing, so a new one is written and fails.
        $root = $this->tmpDir . DIRECTORY_SEPARATOR . 'extracted';
        mkdir($root . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'encryption.xml', 0777, true);

        try {
            (new FontObfuscation($root))->setAlgorithm('EPUB/fonts/font.ttf', FontObfuscation::IDPF);
            $this->fail('No exception was thrown.');
        } catch (Exception $exception) {
            $this->assertSame('Failed to save XML file: META-INF/encryption.xml', $exception->getMessage());
        }
    }

    public function testADirectoryThatCannotBeCreatedIsNamedRelativeToTheBook(): void
    {
        $content = EpubFile::open(EpubBuilder::epub3()->buildEpub($this->tmpDir . '/book.epub'))->getContentManager();

        try {
            // The chapter is a file, so no directory can be created below it.
            $content->addContent('EPUB/text/chapter.xhtml/note.xhtml', EpubBuilder::xhtml('Note', '<p>x</p>'));
            $this->fail('No exception was thrown.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('EPUB/text/chapter.xhtml/note.xhtml', $exception->getMessage());
            $this->assertNoExtractionPath($exception->getMessage());
        }
    }

    private function openFailure(EpubBuilder $builder): string
    {
        try {
            EpubFile::open($builder->buildEpub($this->tmpDir . '/' . uniqid('book', true) . '.epub'));
        } catch (Exception $exception) {
            return $exception->getMessage();
        }

        $this->fail('The book was opened.');
    }

    private function assertNoExtractionPath(string $message): void
    {
        $this->assertStringNotContainsString(sys_get_temp_dir(), $message);
        $this->assertDoesNotMatchRegularExpression('/epub_[0-9a-f]{8,}/', $message);
    }
}
