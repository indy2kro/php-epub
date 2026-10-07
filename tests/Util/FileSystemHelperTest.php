<?php

declare(strict_types=1);

namespace PhpEpub\Test\Util;

use PhpEpub\Exception;
use PhpEpub\Test\Support\UnreadableFile;
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

    public function testReadFile(): void
    {
        $directory = $this->fixturesDir . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'read';
        mkdir($directory, 0777, true);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'text.txt', 'content');
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'empty.txt', '');
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'locked.txt', 'secret');
        $locked = UnreadableFile::make($directory . DIRECTORY_SEPARATOR . 'locked.txt');

        try {
            $this->assertSame('content', FileSystemHelper::readFile($directory . DIRECTORY_SEPARATOR . 'text.txt'));
            $this->assertSame('', FileSystemHelper::readFile($directory . DIRECTORY_SEPARATOR . 'empty.txt'));
            $this->assertNull(FileSystemHelper::readFile($directory . DIRECTORY_SEPARATOR . 'missing.txt'));
            $this->assertNull(FileSystemHelper::readFile($directory));

            if (! $locked->isUnreadable()) {
                $this->markTestSkipped('Unreadable files are readable here (e.g. running as root).');
            }

            // On Windows a locked file reads as "" with a notice; it must not look like an empty file.
            $this->assertNull(FileSystemHelper::readFile($directory . DIRECTORY_SEPARATOR . 'locked.txt'));
        } finally {
            $locked->restore();
            $this->helper->deleteDirectory($directory);
        }
    }

    public function testFileExists(): void
    {
        $validFile = $this->fixturesDir . DIRECTORY_SEPARATOR . 'valid.epub';
        $invalidFile = $this->fixturesDir . DIRECTORY_SEPARATOR . 'nonexistent.epub';

        $this->assertTrue($this->helper->fileExists($validFile));
        $this->assertFalse($this->helper->fileExists($invalidFile));
    }

    public function testRunProcessReturnsExitCodeAndCombinedOutput(): void
    {
        $result = $this->helper->runProcess(
            [PHP_BINARY, '-r', 'echo "to stdout "; fwrite(STDERR, "to stderr"); exit(3);'],
            30
        );

        $this->assertSame(3, $result['exitCode']);
        $this->assertStringContainsString('to stdout', $result['output']);
        $this->assertStringContainsString('to stderr', $result['output']);
    }

    public function testRunProcessPassesArgumentsWithoutAShell(): void
    {
        $result = $this->helper->runProcess([PHP_BINARY, '-r', 'echo $argv[1];', 'A & B; echo injected'], 30);

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('A & B; echo injected', $result['output']);
    }

    public function testRunProcessStopsACommandThatRunsTooLong(): void
    {
        $started = microtime(true);

        try {
            $this->helper->runProcess([PHP_BINARY, '-r', 'sleep(30);'], 1);
            $this->fail('Expected the command to time out.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('timed out after 1 seconds', $exception->getMessage());
        }

        $this->assertLessThan(10, microtime(true) - $started);
    }

    public function testRunProcessWithoutATimeoutWaitsForTheProgram(): void
    {
        $result = $this->helper->runProcess([PHP_BINARY, '-r', 'usleep(200000); echo "done";'], null);

        $this->assertSame(['exitCode' => 0, 'output' => 'done'], $result);
    }

    public function testFindExecutableLooksUpBareNamesOnThePath(): void
    {
        $directory = dirname(PHP_BINARY);
        // Only Windows needs the extension removed; elsewhere names like "php8.3" are the whole program name.
        $name = DIRECTORY_SEPARATOR === '\\' ? pathinfo(PHP_BINARY, PATHINFO_FILENAME) : basename(PHP_BINARY);
        $path = getenv('PATH');
        putenv('PATH=' . $this->fixturesDir . PATH_SEPARATOR . PATH_SEPARATOR . $directory);

        try {
            $found = $this->helper->findExecutable($name);
            $this->assertNotNull($found);
            $this->assertSame(realpath(PHP_BINARY), realpath($found));
            $this->assertNull($this->helper->findExecutable('no-such-program-' . bin2hex(random_bytes(4))));
        } finally {
            putenv('PATH=' . $path);
        }
    }

    public function testFindExecutableChecksPathsAsTheyAre(): void
    {
        $this->assertSame(PHP_BINARY, $this->helper->findExecutable(PHP_BINARY));
        $this->assertNull($this->helper->findExecutable($this->fixturesDir . DIRECTORY_SEPARATOR . 'no-such-program'));
    }

    public function testFindExecutableUsesPathextOnWindows(): void
    {
        $directory = $this->fixturesDir . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'pathext';
        mkdir($directory, 0777, true);
        // Not executable: on Windows the extension is what matters.
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'tool.cmd', '@echo off');
        $windows = new class () extends FileSystemHelper {
            protected function isWindows(): bool
            {
                return true;
            }
        };
        $path = getenv('PATH');
        $pathExt = getenv('PATHEXT');
        putenv('PATH=' . $directory);
        putenv('PATHEXT=.COM;.CMD');

        try {
            $this->assertSame($directory . DIRECTORY_SEPARATOR . 'tool.cmd', $windows->findExecutable('tool'));
        } finally {
            putenv('PATH=' . $path);
            putenv($pathExt === false ? 'PATHEXT' : 'PATHEXT=' . $pathExt);
            $this->helper->deleteDirectory($directory);
        }
    }

    public function testRunProcessReportsAProgramThatFailsToStart(): void
    {
        $failing = new class () extends FileSystemHelper {
            protected function startProcess(array $command, $output)
            {
                return false;
            }
        };

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to start');

        $failing->runProcess([PHP_BINARY, '-v'], 5);
    }

    public function testRunProcessReportsACommandThatCannotStart(): void
    {
        $this->expectException(Exception::class);

        $this->helper->runProcess([$this->fixturesDir . DIRECTORY_SEPARATOR . 'no-such-program'], 5);
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
