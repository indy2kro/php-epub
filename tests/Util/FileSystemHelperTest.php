<?php

declare(strict_types=1);

namespace PhpEpub\Test\Util;

use PhpEpub\Exception;
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
        $name = pathinfo(PHP_BINARY, PATHINFO_FILENAME);
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
