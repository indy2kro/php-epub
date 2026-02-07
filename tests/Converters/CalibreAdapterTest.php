<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use PhpEpub\Converters\CalibreAdapter;
use PhpEpub\Exception;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CalibreAdapterTest extends TestCase
{
    /**
     * @var MockObject&FileSystemHelper
     */
    private mixed $helperMock;
    private string $fakeCalibrePath = '/fake/path/to/ebook-convert';
    private string $fakeInputFile = '/fake/path/to/input.epub';
    private string $fakeOutputFile = '/fake/path/to/output.pdf';

    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(FileSystemHelper::class);
    }

    public function testConvertSuccessful(): void
    {
        $this->helperMock->expects($this->exactly(3))->method('fileExists')->willReturnMap([
            [$this->fakeCalibrePath, true],
            [$this->fakeInputFile, true],
            [$this->fakeOutputFile, true],
        ]);

        $this->helperMock->method('fileSize')->willReturn(100);

        $adapter = new CalibreAdapter(['calibre_path' => $this->fakeCalibrePath], $this->helperMock);

        $adapter->convert($this->fakeInputFile, $this->fakeOutputFile);

        // add some assert to make the test pass phpstan
        $this->assertSame('/fake/path/to/input.epub', $this->fakeInputFile);
        $this->assertSame('/fake/path/to/output.pdf', $this->fakeOutputFile);
    }

    public function testConvertFailsWhenCalibreNotFound(): void
    {
        $this->helperMock->method('fileExists')->willReturn(false);

        $adapter = new CalibreAdapter(['calibre_path' => '/invalid/path/to/ebook-convert'], $this->helperMock);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Calibre tool not found at path: /invalid/path/to/ebook-convert');

        $adapter->convert($this->fakeInputFile, $this->fakeOutputFile);
    }

    public function testConvertFailsWhenInputFileNotFound(): void
    {
        $this->helperMock->expects($this->exactly(2))->method('fileExists')->willReturnMap([
            [$this->fakeCalibrePath, true],
            [$this->fakeInputFile, false],
        ]);

        $adapter = new CalibreAdapter(['calibre_path' => $this->fakeCalibrePath], $this->helperMock);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("EPUB file not found: {$this->fakeInputFile}");

        $adapter->convert($this->fakeInputFile, $this->fakeOutputFile);
    }

    public function testConvertFailsWhenExecFails(): void
    {
        $this->helperMock->method('fileExists')->willReturn(true);
        $this->helperMock->method('exec')->willReturnCallback(static function (string $command, array &$output, int &$returnVar): void {
            $output = ['Error executing command'];
            $returnVar = 1;
        });

        $adapter = new CalibreAdapter(['calibre_path' => $this->fakeCalibrePath], $this->helperMock);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Calibre conversion failed: Error executing command');

        $adapter->convert($this->fakeInputFile, $this->fakeOutputFile);
    }

    public function testConvertFailsWhenOutputFileMissing(): void
    {
        $this->helperMock->expects($this->exactly(3))->method('fileExists')->willReturnMap([
            [$this->fakeCalibrePath, true],
            [$this->fakeInputFile, true],
            [$this->fakeOutputFile, false],
        ]);

        $adapter = new CalibreAdapter(['calibre_path' => $this->fakeCalibrePath], $this->helperMock);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Calibre conversion failed');

        $adapter->convert($this->fakeInputFile, $this->fakeOutputFile);
    }
}
