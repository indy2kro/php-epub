<?php

declare(strict_types=1);

namespace PhpEpub\Test\Util;

use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

final class FileSystemHelperTest extends TestCase
{
    private FileSystemHelper $helper;
    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new FileSystemHelper();
        $this->fixturesDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures';
    }

    public function testFileExists(): void
    {
        $validFile = $this->fixturesDir . DIRECTORY_SEPARATOR . 'valid.epub';
        $invalidFile = $this->fixturesDir . DIRECTORY_SEPARATOR . 'nonexistent.epub';

        $this->assertTrue($this->helper->fileExists($validFile));
        $this->assertFalse($this->helper->fileExists($invalidFile));
    }

    public function testExecSuccessful(): void
    {
        $phpBinary = PHP_BINARY;
        if (DIRECTORY_SEPARATOR === '\\') {
            $phpBinary = '"' . $phpBinary . '"';
        }
        $command = $phpBinary . ' -r "echo \"Hello, world!\";"';
        $output = [];
        $returnVar = 0;

        $this->helper->exec($command, $output, $returnVar);

        $this->assertSame(0, $returnVar);
        $this->assertSame(['Hello, world!'], $output);
    }

    public function testFileSize(): void
    {
        $validFile = $this->fixturesDir . DIRECTORY_SEPARATOR . 'valid.epub';
        $invalidFile = $this->fixturesDir . DIRECTORY_SEPARATOR . 'nonexistent.epub';

        $this->assertSame(filesize($validFile), $this->helper->fileSize($validFile));
        $this->assertFalse(@$this->helper->fileSize($invalidFile));
    }

    public function testDeleteDirectoryOfAMissingPathSucceeds(): void
    {
        $this->assertTrue($this->helper->deleteDirectory($this->fixturesDir . DIRECTORY_SEPARATOR . 'does-not-exist'));
    }

    public function testDeleteDirectoryDoesNotFollowSymlinks(): void
    {
        $base = $this->fixturesDir . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'symlink_test';
        $book = $base . DIRECTORY_SEPARATOR . 'book';
        $outside = $base . DIRECTORY_SEPARATOR . 'outside';
        mkdir($book, 0777, true);
        mkdir($outside, 0777, true);
        file_put_contents($outside . DIRECTORY_SEPARATOR . 'keep.txt', 'keep');

        try {
            if (! @symlink($outside, $book . DIRECTORY_SEPARATOR . 'link')) {
                $this->markTestSkipped('Creating symlinks is not permitted on this system.');
            }

            $this->assertTrue($this->helper->deleteDirectory($book));

            $this->assertDirectoryDoesNotExist($book);
            $this->assertFileExists($outside . DIRECTORY_SEPARATOR . 'keep.txt');
        } finally {
            $this->helper->deleteDirectory($base);
        }
    }

    public function testDeleteDirectoryReportsADirectoryItCannotList(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows has no "write and enter, but not list" directory permission.');
        }

        $base = $this->fixturesDir . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'unlistable';
        mkdir($base . DIRECTORY_SEPARATOR . 'inner', 0777, true);
        chmod($base . DIRECTORY_SEPARATOR . 'inner', 0300);

        try {
            if (is_readable($base . DIRECTORY_SEPARATOR . 'inner')) {
                $this->markTestSkipped('Unreadable directories are readable here (e.g. running as root).');
            }

            $this->assertFalse($this->helper->deleteDirectory($base));
        } finally {
            chmod($base . DIRECTORY_SEPARATOR . 'inner', 0777);
            $this->helper->deleteDirectory($base);
        }
    }

    public function testDeleteDirectoryReportsFailureWithoutWarnings(): void
    {
        $base = $this->fixturesDir . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'undeletable';
        $nested = $base . DIRECTORY_SEPARATOR . 'nested';
        mkdir($nested, 0777, true);
        file_put_contents($nested . DIRECTORY_SEPARATOR . 'file.txt', 'x');

        // Windows cannot remove a directory while a file in it is open; POSIX cannot unlink from a read-only directory.
        $handle = fopen($nested . DIRECTORY_SEPARATOR . 'file.txt', 'r');
        chmod($nested, 0500);

        try {
            if (DIRECTORY_SEPARATOR === '/' && is_writable($nested)) {
                $this->markTestSkipped('Read-only directories are writable here (e.g. running as root).');
            }

            // failOnWarning turns any warning from unlink()/rmdir() into a failure.
            $this->assertFalse($this->helper->deleteDirectory($base));
            $this->assertDirectoryExists($base);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            chmod($nested, 0777);
            $this->helper->deleteDirectory($base);
        }
    }
}
