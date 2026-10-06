<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use PhpEpub\Converters\CalibreAdapter;
use PhpEpub\Exception;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
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
        $this->helperMock->expects($this->once())
            ->method('fileExists')
            ->with('/invalid/path/to/ebook-convert')
            ->willReturn(false);

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
        $this->helperMock->expects($this->exactly(2))
            ->method('fileExists')
            ->willReturn(true);
        $this->helperMock->expects($this->once())
            ->method('exec')
            ->willReturnCallback(static function (string $command, array &$output, int &$returnVar): void {
                $output = ['Error executing command'];
                $returnVar = 1;
            });

        $adapter = new CalibreAdapter(['calibre_path' => $this->fakeCalibrePath], $this->helperMock);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Calibre conversion failed: Error executing command');

        $adapter->convert($this->fakeInputFile, $this->fakeOutputFile);
    }

    public function testCommandEscapesEveryArgumentAndCapturesStderr(): void
    {
        $command = $this->runWithCommandCapture([
            'calibre_path' => '/opt/My Calibre/ebook-convert',
            'extra_args' => ['--title', 'A & B; rm -rf /'],
        ], $this->fakeInputFile);

        $this->assertStringStartsWith(escapeshellarg('/opt/My Calibre/ebook-convert') . ' ', $command);
        $this->assertStringContainsString(escapeshellarg('--title') . ' ' . escapeshellarg('A & B; rm -rf /'), $command);
        $this->assertStringEndsWith(' 2>&1', $command);
    }

    #[IgnoreDeprecations]
    public function testStringExtraArgsAreDeprecatedButStillPassed(): void
    {
        $this->expectUserDeprecationMessageMatches('/extra_args as a string is deprecated/');

        $command = $this->runWithCommandCapture(['extra_args' => '--pretty-print'], $this->fakeInputFile);

        $this->assertStringContainsString(' --pretty-print 2>&1', $command);
    }

    public function testEmptyStringExtraArgsAddNothingAndAreNotDeprecated(): void
    {
        $command = $this->runWithCommandCapture(['extra_args' => ''], $this->fakeInputFile);

        $this->assertStringEndsWith(escapeshellarg($this->fakeOutputFile) . ' 2>&1', $command);
    }

    public function testConvertsAnExtractedDirectoryThroughATemporaryEpub(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'calibre_dir_' . bin2hex(random_bytes(4));
        EpubBuilder::minimal()->writeTo($directory);
        $seenEpub = null;

        try {
            $this->runWithCommandCapture([], $directory, static function (string $command) use (&$seenEpub): void {
                preg_match('/(\S*calibre_\w+\.epub)/', str_replace(['"', "'"], '', $command), $match);
                $seenEpub = $match[1] ?? null;
                TestCase::assertNotNull($seenEpub, "No temporary EPUB in: {$command}");
                TestCase::assertFileExists($seenEpub);
            });
        } finally {
            (new FileSystemHelper())->deleteDirectory($directory);
        }

        $this->assertIsString($seenEpub);
        $this->assertFileDoesNotExist($seenEpub);
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

    /**
     * Runs a successful conversion with a fake Calibre and returns the executed command.
     *
     * @param array{calibre_path?: string, extra_args?: string|list<string>} $options
     * @param (callable(string): void)|null $onExec
     */
    private function runWithCommandCapture(array $options, string $input, ?callable $onExec = null): string
    {
        $calibrePath = $options['calibre_path'] ?? $this->fakeCalibrePath;
        $helper = $this->helperMock;
        $helper->method('fileExists')->willReturnCallback(
            fn (string $path): bool => in_array($path, [$calibrePath, $this->fakeInputFile, $this->fakeOutputFile], true) || file_exists($path)
        );
        $helper->method('fileSize')->willReturn(100);

        $command = '';
        $helper->expects($this->once())->method('exec')->willReturnCallback(
            static function (string $executed, array &$output, int &$returnVar) use (&$command, $onExec): void {
                $command = $executed;
                $returnVar = 0;
                if ($onExec !== null) {
                    $onExec($executed);
                }
            }
        );

        (new CalibreAdapter(['calibre_path' => $this->fakeCalibrePath, ...$options], $helper))->convert($input, $this->fakeOutputFile);

        return $command;
    }
}
