<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Limits;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Test\Support\UnreadableFile;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ZipException;
use PhpEpub\ZipHandler;
use PHPUnit\Framework\TestCase;

/**
 * Books as expanded (unzipped) directories: EpubFile::openDirectory() and saveToDirectory().
 */
final class ExpandedDirectoryTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_expanded_' . bin2hex(random_bytes(8));
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->workDir);
    }

    private function bookDirectory(?EpubBuilder $builder = null): string
    {
        $directory = $this->workDir . '/source';
        mkdir($directory);

        return ($builder ?? EpubBuilder::epub3())->writeTo($directory);
    }

    public function testOpensAnExpandedBookWithoutChangingIt(): void
    {
        $directory = $this->bookDirectory();
        $before = $this->listing($directory);

        $epubFile = EpubFile::openDirectory($directory);
        $epubFile->getMetadata()->setTitle('Edited');
        $this->assertNotSame($directory, $epubFile->getTempDir());
        $epubFile->cleanup();

        $this->assertSame($before, $this->listing($directory));
        $this->assertSame(EpubFile::open(EpubBuilder::epub3()->buildEpub($this->workDir . '/a.epub'))->getMetadata()->getTitle(), EpubFile::openDirectory($directory)->getMetadata()->getTitle());
    }

    public function testSaveToDirectoryRoundTrips(): void
    {
        $epubFile = EpubFile::openDirectory($this->bookDirectory());
        $epubFile->getMetadata()->setTitle('Expanded Title');
        $target = $this->workDir . '/out/nested';

        $epubFile->saveToDirectory($target);

        $this->assertSame('application/epub+zip', file_get_contents($target . '/mimetype'));
        $this->assertSame('Expanded Title', EpubFile::openDirectory($target)->getMetadata()->getTitle());
        // The expanded copy packs into a valid archive.
        $archive = $this->workDir . '/packed.epub';
        $reopened = EpubFile::openDirectory($target);
        $reopened->save($archive);
        $this->assertSame('Expanded Title', EpubFile::open($archive)->getMetadata()->getTitle());
    }

    public function testAnArchiveCanBeSavedToADirectoryAndBack(): void
    {
        $path = EpubBuilder::epub3()->buildEpub($this->workDir . '/book.epub');
        $epubFile = EpubFile::open($path);
        $target = $this->workDir . '/expanded';

        $epubFile->saveToDirectory($target);

        $this->assertFileExists($target . '/META-INF/container.xml');
        $this->assertSame($epubFile->getMetadata()->getTitle(), EpubFile::openDirectory($target)->getMetadata()->getTitle());
    }

    public function testSaveToDirectoryWritesAnExactMimetype(): void
    {
        $epubFile = EpubFile::openDirectory($this->bookDirectory(EpubBuilder::epub3()->withFile('mimetype', "application/epub+zip\n")));
        $target = $this->workDir . '/out';

        $epubFile->saveToDirectory($target);

        $this->assertSame('application/epub+zip', file_get_contents($target . '/mimetype'));
    }

    public function testSaveToDirectoryAcceptsAnEmptyDirectoryAndRefusesOthers(): void
    {
        $epubFile = EpubFile::openDirectory($this->bookDirectory());
        $empty = $this->workDir . '/empty';
        mkdir($empty);

        $epubFile->saveToDirectory($empty);
        $this->assertFileExists($empty . '/mimetype');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not empty');
        $epubFile->saveToDirectory($empty);
    }

    public function testSaveToDirectoryRefusesAFile(): void
    {
        $epubFile = EpubFile::openDirectory($this->bookDirectory());
        file_put_contents($this->workDir . '/file', 'x');

        $this->expectException(Exception::class);
        $epubFile->saveToDirectory($this->workDir . '/file');
    }

    public function testSaveToDirectoryRejectsAnEmptyPath(): void
    {
        $epubFile = EpubFile::openDirectory($this->bookDirectory());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('path must not be empty');
        $epubFile->saveToDirectory('');
    }

    public function testSaveToDirectoryNeedsALoadedBook(): void
    {
        $epubFile = EpubFile::openDirectory($this->bookDirectory());
        $epubFile->close();

        $this->expectException(Exception::class);
        $epubFile->saveToDirectory($this->workDir . '/out');
    }

    public function testABookFromADirectoryHasNoFileToOverwrite(): void
    {
        $epubFile = EpubFile::openDirectory($this->bookDirectory());

        try {
            $epubFile->save();
            $this->fail('Expected an exception.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('directory', $exception->getMessage());
        }

        $this->expectException(Exception::class);
        $epubFile->load();
    }

    public function testOpenDirectoryRejectsAMissingDirectoryAndAFile(): void
    {
        try {
            EpubFile::openDirectory($this->workDir . '/missing');
            $this->fail('Expected an exception.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('does not exist', $exception->getMessage());
        }

        file_put_contents($this->workDir . '/file', 'x');
        $this->expectException(Exception::class);
        EpubFile::openDirectory($this->workDir . '/file');
    }

    public function testOpenDirectoryRejectsADirectoryThatIsNotABook(): void
    {
        $directory = $this->workDir . '/empty';
        mkdir($directory);

        $this->expectException(Exception::class);
        EpubFile::openDirectory($directory);
    }

    public function testOpenDirectoryAppliesTheLimitsAndLeavesNothingBehind(): void
    {
        $directory = $this->bookDirectory();
        $before = $this->scratchEntries();

        try {
            EpubFile::openDirectory($directory, limits: new Limits(maxEntries: 2));
            $this->fail('Expected a ZipException.');
        } catch (ZipException $exception) {
            $this->assertStringContainsString('too many', $exception->getMessage());
        }

        $this->assertSame($before, $this->scratchEntries());

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('maximum uncompressed size');
        EpubFile::openDirectory($directory, new ZipHandler(maxUncompressedBytes: 10));
    }

    public function testOpenDirectoryRefusesSymbolicLinks(): void
    {
        $directory = $this->bookDirectory();
        file_put_contents($this->workDir . '/secret.txt', 'secret');
        if (! @symlink($this->workDir . '/secret.txt', $directory . '/EPUB/link.txt')) {
            $this->markTestSkipped('Symbolic links cannot be created here.');
        }

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('symbolic link');
        EpubFile::openDirectory($directory);
    }

    public function testSaveToDirectoryLeavesNothingBehindWhenAFileCannotBeCopied(): void
    {
        foreach (['created' => false, 'empty' => true] as $label => $exists) {
            $epubFile = EpubFile::openDirectory($this->bookDirectory());
            $target = $this->workDir . '/out-' . $label;
            $exists && mkdir($target);
            $unreadable = UnreadableFile::make($epubFile->getTempDir() . '/EPUB/css/style.css');

            try {
                // A Windows lock stops reads but not a copy, and root reads everything.
                if (! $unreadable->isUnreadable() || @copy($epubFile->getTempDir() . '/EPUB/css/style.css', $this->workDir . '/probe')) {
                    $this->markTestSkipped('The file can still be copied.');
                }

                try {
                    $epubFile->saveToDirectory($target);
                    $this->fail('Expected the copy to fail.');
                } catch (Exception $exception) {
                    $this->assertStringContainsString('Failed to copy', $exception->getMessage(), $label);
                }

                // Nothing half written: the directory is missing or empty again, as it was before.
                $this->assertSame($exists, is_dir($target), $label);
                $exists && $this->assertSame(['.', '..'], scandir($target), $label);
            } finally {
                $unreadable->restore();
                $epubFile->cleanup();
                (new FileSystemHelper())->deleteDirectory($this->workDir . '/source');
            }
        }
    }

    public function testSaveToDirectoryLeavesSymbolicLinksOut(): void
    {
        $epubFile = EpubFile::openDirectory($this->bookDirectory());
        $tempDir = (string) $epubFile->getTempDir();
        if (! @symlink($tempDir . '/EPUB/css/style.css', $tempDir . '/EPUB/css/link.css')) {
            $this->markTestSkipped('Symbolic links cannot be created here.');
        }

        $epubFile->saveToDirectory($this->workDir . '/linked');

        $this->assertFileExists($this->workDir . '/linked/EPUB/css/style.css');
        $this->assertFileDoesNotExist($this->workDir . '/linked/EPUB/css/link.css');
        $epubFile->cleanup();
    }

    /**
     * @return list<string>
     */
    private function listing(string $directory): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($directory)) . ':' . md5((string) file_get_contents($file->getPathname()));
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function scratchEntries(): array
    {
        return array_values(array_filter(scandir(sys_get_temp_dir()) ?: [], static fn (string $name): bool => str_starts_with($name, 'epub_') && ! str_starts_with($name, 'epub_expanded_')));
    }
}
