<?php

declare(strict_types=1);

namespace PhpEpub\Test\Util;

use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

class FileSystemHelperTest extends TestCase
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
        $command = PHP_BINARY . ' -r "echo \"Hello, world!\";"';
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

        $this->assertSame(87515, $this->helper->fileSize($validFile));
        $this->assertFalse(@$this->helper->fileSize($invalidFile));
    }
}
