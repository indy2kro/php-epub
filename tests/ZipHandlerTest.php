<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ZipException;
use PhpEpub\ZipHandler;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ZipHandlerTest extends TestCase
{
    private string $validZipPath;
    private string $invalidZipPath;
    private string $extractDir;
    private string $compressDir;
    private string $outputZipPath;
    private FileSystemHelper $fileSystemHelper;

    protected function setUp(): void
    {
        $this->fileSystemHelper = new FileSystemHelper();
        $this->validZipPath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.zip';
        $this->invalidZipPath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'invalid.zip';
        $this->extractDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'extracted';
        $this->compressDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'compress';
        $this->outputZipPath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'output.zip';

        // Ensure the directories exist
        if (! is_dir($this->extractDir)) {
            mkdir($this->extractDir, 0777, true);
        }
        if (! is_dir($this->compressDir)) {
            mkdir($this->compressDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any files or directories created during tests
        if (file_exists($this->outputZipPath)) {
            unlink($this->outputZipPath);
        }

        $builtZipPath = dirname($this->extractDir) . DIRECTORY_SEPARATOR . 'built.zip';
        if (file_exists($builtZipPath)) {
            unlink($builtZipPath);
        }

        if (is_dir($this->extractDir)) {
            $this->fileSystemHelper->deleteDirectory($this->extractDir);
        }

        if (is_dir($this->compressDir)) {
            $this->fileSystemHelper->deleteDirectory($this->compressDir);
        }
    }

    public function testExtractValidZip(): void
    {
        $zipHandler = new ZipHandler();
        $zipHandler->extract($this->validZipPath, $this->extractDir);

        $this->assertDirectoryExists($this->extractDir);
        $this->assertNotEmpty(scandir($this->extractDir));
    }

    public function testExtractInvalidZipThrowsException(): void
    {
        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('Failed to open ZIP file:');

        $zipHandler = new ZipHandler();
        $zipHandler->extract($this->invalidZipPath, $this->extractDir);
    }

    public function testExtractNonExistentZipThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('ZIP file does not exist:');

        $zipHandler = new ZipHandler();
        $zipHandler->extract(__DIR__ . DIRECTORY_SEPARATOR . 'nonexistent.zip', $this->extractDir);
    }

    public function testExtractKeepsNestedEntriesAndDirectories(): void
    {
        $zipPath = $this->buildZip(['a/b/c.txt' => 'nested', 'd/' => null, 'top.txt' => 'top']);

        (new ZipHandler())->extract($zipPath, $this->extractDir);

        $this->assertStringEqualsFile($this->extractDir . '/a/b/c.txt', 'nested');
        $this->assertStringEqualsFile($this->extractDir . '/top.txt', 'top');
        $this->assertDirectoryExists($this->extractDir . '/d');
    }

    public function testExtractRejectsEntryNamesOutsideTheDestination(): void
    {
        $zipPath = $this->buildZip(['../evil.txt' => 'planted']);
        $outside = dirname($this->extractDir) . DIRECTORY_SEPARATOR . 'evil.txt';

        try {
            (new ZipHandler())->extract($zipPath, $this->extractDir);
            $this->fail('Expected an exception for an entry outside the destination.');
        } catch (ZipException $exception) {
            $this->assertStringContainsString('outside the EPUB', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($outside);
        $this->assertFileDoesNotExist($this->extractDir . DIRECTORY_SEPARATOR . 'evil.txt');
    }

    public function testExtractRejectsTooManyEntries(): void
    {
        $zipPath = $this->buildZip(['1.txt' => '1', '2.txt' => '2', '3.txt' => '3', '4.txt' => '4']);

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('too many entries');

        (new ZipHandler(maxEntries: 3))->extract($zipPath, $this->extractDir);
    }

    public function testExtractRejectsArchivesLargerThanTheSizeLimit(): void
    {
        $zipPath = $this->buildZip(['big.txt' => random_bytes(5000)]);

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('exceeds the maximum uncompressed size');

        (new ZipHandler(maxUncompressedBytes: 1000))->extract($zipPath, $this->extractDir);
    }

    public function testExtractRejectsZipBombCompressionRatio(): void
    {
        // 8 MiB of zeros deflates to a few KiB.
        $zipPath = $this->buildZip(['bomb.txt' => str_repeat("\0", 8 * 1024 * 1024)]);

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('compression ratio');

        (new ZipHandler())->extract($zipPath, $this->extractDir);
    }

    public function testExtractRejectsCorruptedEntryData(): void
    {
        $zipPath = $this->buildZip(['a.txt' => str_repeat('hello world ', 5000)]);
        $bytes = (string) file_get_contents($zipPath);
        // Flip bytes inside the deflated data of the first entry (after its 30-byte header and name).
        for ($i = 40; $i < 60; $i++) {
            $bytes[$i] = chr(ord($bytes[$i]) ^ 0xFF);
        }
        file_put_contents($zipPath, $bytes);

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('Failed to read ZIP entry: a.txt');

        (new ZipHandler())->extract($zipPath, $this->extractDir);
    }

    public function testExtractRejectsUnsupportedCompressionMethod(): void
    {
        $zipPath = $this->buildZip(['b.txt' => 'data'], ZipArchive::CM_STORE);
        $bytes = (string) file_get_contents($zipPath);
        // Method 1 ("shrink") is not supported by libzip: patch the local and central headers.
        $bytes[8] = chr(1);
        $centralDirectory = (int) strpos($bytes, "PK\x01\x02");
        $bytes[$centralDirectory + 10] = chr(1);
        file_put_contents($zipPath, $bytes);

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('Failed to read ZIP entry: b.txt');

        (new ZipHandler())->extract($zipPath, $this->extractDir);
    }

    public function testExtractRejectsFileEntryWhereADirectoryExists(): void
    {
        $zipPath = $this->buildZip(['a/' => null, 'a' => 'file over directory']);

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('Failed to create file for ZIP entry: a');

        (new ZipHandler())->extract($zipPath, $this->extractDir);
    }

    public function testExtractRejectsDirectoryBlockedByAFile(): void
    {
        $zipPath = $this->buildZip(['a' => 'file', 'a/b.txt' => 'needs a/ to be a directory']);

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('Failed to create directory:');

        (new ZipHandler())->extract($zipPath, $this->extractDir);
    }

    public function testCompressDirectory(): void
    {
        // Create a sample file to compress
        $sampleFilePath = $this->compressDir . DIRECTORY_SEPARATOR . 'sample.txt';
        file_put_contents($sampleFilePath, 'Sample content');

        $zipHandler = new ZipHandler();
        $zipHandler->compress($this->compressDir, $this->outputZipPath);

        $this->assertFileExists($this->outputZipPath);
    }

    public function testCompressWritesMimetypeFirstAndUncompressed(): void
    {
        $this->createEpubTree();

        $zipHandler = new ZipHandler();
        $zipHandler->compress($this->compressDir, $this->outputZipPath);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->outputZipPath));
        $stat = $zip->statIndex(0);
        $zip->close();

        $this->assertIsArray($stat);
        $this->assertSame('mimetype', $stat['name']);
        $this->assertSame(ZipArchive::CM_STORE, $stat['comp_method']);
    }

    public function testCompressIsDeterministic(): void
    {
        $this->createEpubTree();
        $zipHandler = new ZipHandler();
        $first = $this->outputZipPath . '.first';

        $zipHandler->compress($this->compressDir, $first);
        // Different file times (as after a later extraction) must not change the archive.
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->compressDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($files as $file) {
            $this->assertInstanceOf(\SplFileInfo::class, $file);
            touch($file->getPathname(), time() - 86_400 * 30);
        }
        $zipHandler->compress($this->compressDir, $this->outputZipPath);

        try {
            $this->assertSame(hash_file('sha256', $first), hash_file('sha256', $this->outputZipPath));
        } finally {
            unlink($first);
        }

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->outputZipPath));
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = (string) $zip->getNameIndex($index);
        }
        $zip->close();

        $rest = array_slice($names, 1);
        $sorted = $rest;
        sort($sorted, SORT_STRING);
        $this->assertSame('mimetype', $names[0]);
        $this->assertSame($sorted, $rest);
    }

    public function testCompressUsesForwardSlashesInEntryNames(): void
    {
        $this->createEpubTree();

        $zipHandler = new ZipHandler();
        $zipHandler->compress($this->compressDir, $this->outputZipPath);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->outputZipPath));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertContains('META-INF/container.xml', $names);
        $this->assertContains('EPUB/text/chapter.xhtml', $names);
        foreach ($names as $name) {
            $this->assertStringNotContainsString('\\', $name);
        }
    }

    public function testCompressNonExistentDirectoryThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid source directory:');

        $zipHandler = new ZipHandler();
        $zipHandler->compress(__DIR__ . DIRECTORY_SEPARATOR . 'nonexistent', $this->outputZipPath);
    }

    public function testCompressNonExistentOutputDirectoryThrowsException(): void
    {
        // Create a sample file to compress
        $sampleFilePath = $this->compressDir . DIRECTORY_SEPARATOR . 'sample.txt';
        file_put_contents($sampleFilePath, 'Sample content');

        $this->expectException(Exception::class);

        $zipHandler = new ZipHandler();
        @$zipHandler->compress($this->compressDir, __DIR__ . DIRECTORY_SEPARATOR . 'nonexistent' . DIRECTORY_SEPARATOR . 'output.zip');
    }

    /**
     * @param array<string, string|null> $entries entry name => content (null for a directory)
     */
    private function buildZip(array $entries, ?int $compression = null): string
    {
        $zipPath = dirname($this->extractDir) . DIRECTORY_SEPARATOR . 'built.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $content === null ? $zip->addEmptyDir($name) : $zip->addFromString($name, $content);
            if ($compression !== null) {
                $zip->setCompressionName($name, $compression);
            }
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * Creates a minimal EPUB layout whose other entries sort before "mimetype".
     */
    private function createEpubTree(): void
    {
        $metaInf = $this->compressDir . DIRECTORY_SEPARATOR . 'META-INF';
        $text = $this->compressDir . DIRECTORY_SEPARATOR . 'EPUB' . DIRECTORY_SEPARATOR . 'text';
        mkdir($metaInf, 0777, true);
        mkdir($text, 0777, true);

        file_put_contents($metaInf . DIRECTORY_SEPARATOR . 'container.xml', '<container/>');
        file_put_contents($text . DIRECTORY_SEPARATOR . 'chapter.xhtml', str_repeat('<p>text</p>', 100));
        file_put_contents($this->compressDir . DIRECTORY_SEPARATOR . 'mimetype', 'application/epub+zip');
    }
}
