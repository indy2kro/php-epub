<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Test\Support\RecordingZipHandler;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

final class TempDirHygieneTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_hygiene_' . bin2hex(random_bytes(8));
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->workDir);
    }

    public function testCloseRemovesTheExtractionAndIsIdempotent(): void
    {
        $epubFile = EpubFile::open(EpubBuilder::epub3()->buildEpub($this->workDir . '/book.epub'));
        $directory = (string) $epubFile->getTempDir();
        $this->assertDirectoryExists($directory);

        $epubFile->close();
        $epubFile->close();
        $epubFile->cleanup();

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertNull($epubFile->getTempDir());

        $this->expectException(Exception::class);
        $epubFile->getMetadata();
    }

    public function testAFailedOpenLeavesNoDirectory(): void
    {
        $path = EpubBuilder::epub3()->buildEpub($this->workDir . '/book.epub');
        $zipHandler = new RecordingZipHandler(maxEntries: 1);

        try {
            EpubFile::open($path, $zipHandler);
            $this->fail('Expected an exception.');
        } catch (Exception) {
            $this->assertNotNull($zipHandler->destination);
            $this->assertDirectoryDoesNotExist((string) $zipHandler->destination);
        }
    }

    public function testAnInvalidBookLeavesNoDirectory(): void
    {
        $path = EpubBuilder::epub3()->withoutFile('META-INF/container.xml')->buildEpub($this->workDir . '/book.epub');
        $zipHandler = new RecordingZipHandler();

        try {
            EpubFile::open($path, $zipHandler);
            $this->fail('Expected an exception.');
        } catch (Exception) {
            $this->assertNotNull($zipHandler->destination);
            $this->assertDirectoryDoesNotExist((string) $zipHandler->destination);
        }
    }
}
