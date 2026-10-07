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
        $this->helperMock->expects($this->once())->method('runProcess')->willReturn(['exitCode' => 0, 'output' => '']);

        $adapter = new CalibreAdapter(['calibre_path' => $this->fakeCalibrePath], $this->helperMock);

        $adapter->convert($this->fakeInputFile, $this->fakeOutputFile);
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

    public function testWithoutACalibrePathEbookConvertIsFoundOnThePath(): void
    {
        $this->helperMock->method('findExecutable')->with('ebook-convert')->willReturn('/found/on/path/ebook-convert');
        $this->helperMock->method('fileExists')->willReturn(true);
        $this->helperMock->method('fileSize')->willReturn(100);
        $program = $this->captureProgram();

        (new CalibreAdapter([], $this->helperMock))->convert($this->fakeInputFile, $this->fakeOutputFile);

        $this->assertSame('/found/on/path/ebook-convert', $program->value);
    }

    public function testWithoutACalibrePathTheUsualInstallLocationsAreTried(): void
    {
        $macOs = '/Applications/calibre.app/Contents/MacOS/ebook-convert';
        $this->helperMock->method('findExecutable')->willReturn(null);
        $this->helperMock->method('fileExists')->willReturnCallback(
            fn (string $path): bool => in_array($path, [$macOs, $this->fakeInputFile, $this->fakeOutputFile], true)
        );
        $this->helperMock->method('fileSize')->willReturn(100);
        $program = $this->captureProgram();

        (new CalibreAdapter([], $this->helperMock))->convert($this->fakeInputFile, $this->fakeOutputFile);

        $this->assertSame($macOs, $program->value);
    }

    public function testWithoutACalibrePathAMissingCalibreIsReported(): void
    {
        $this->helperMock->method('findExecutable')->willReturn(null);
        $this->helperMock->method('fileExists')->willReturn(false);
        $this->helperMock->expects($this->never())->method('runProcess');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('ebook-convert was not found on the PATH or in the usual install locations');

        (new CalibreAdapter([], $this->helperMock))->convert($this->fakeInputFile, $this->fakeOutputFile);
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

    public function testConvertFailsWhenCalibreFails(): void
    {
        $this->helperMock->expects($this->exactly(2))
            ->method('fileExists')
            ->willReturn(true);
        $this->helperMock->expects($this->once())
            ->method('runProcess')
            ->willReturn(['exitCode' => 1, 'output' => "Error executing command\n"]);

        $adapter = new CalibreAdapter(['calibre_path' => $this->fakeCalibrePath], $this->helperMock);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Calibre conversion failed: Error executing command');

        $adapter->convert($this->fakeInputFile, $this->fakeOutputFile);
    }

    public function testEveryArgumentIsPassedSeparatelyWithoutAShell(): void
    {
        [$command] = $this->runWithCommandCapture([
            'calibre_path' => '/opt/My Calibre/ebook-convert',
            'extra_args' => ['--title', 'A & B; rm -rf /'],
        ], $this->fakeInputFile);

        $this->assertSame(
            ['/opt/My Calibre/ebook-convert', $this->fakeInputFile, $this->fakeOutputFile, '--title', 'A & B; rm -rf /'],
            $command
        );
    }

    public function testRelativePathsStartingWithADashAreNeverReadAsOptions(): void
    {
        $this->helperMock->method('fileExists')->willReturn(true);
        $this->helperMock->method('fileSize')->willReturn(100);
        $command = [];
        $this->helperMock->expects($this->once())->method('runProcess')->willReturnCallback(
            static function (array $arguments) use (&$command): array {
                $command = $arguments;

                return ['exitCode' => 0, 'output' => ''];
            }
        );

        (new CalibreAdapter(['calibre_path' => $this->fakeCalibrePath], $this->helperMock))->convert('-input.epub', '-x.pdf');

        $this->assertSame(
            [$this->fakeCalibrePath, '.' . DIRECTORY_SEPARATOR . '-input.epub', '.' . DIRECTORY_SEPARATOR . '-x.pdf'],
            $command
        );
    }

    public function testConversionsAreLimitedToTenMinutesByDefault(): void
    {
        [, $timeout] = $this->runWithCommandCapture([], $this->fakeInputFile);

        $this->assertSame(600, $timeout);
    }

    public function testTimeoutOption(): void
    {
        [, $timeout] = $this->runWithCommandCapture(['timeout' => 5], $this->fakeInputFile);
        $this->assertSame(5, $timeout);
    }

    public function testTimeoutCanBeDisabled(): void
    {
        [, $timeout] = $this->runWithCommandCapture(['timeout' => null], $this->fakeInputFile);
        $this->assertNull($timeout);
    }

    public function testTimeoutMustBePositive(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('timeout must be a positive number of seconds or null');

        $this->helperMock->expects($this->never())->method('runProcess');

        new CalibreAdapter(['timeout' => 0], $this->helperMock);
    }

    #[IgnoreDeprecations]
    public function testStringExtraArgsAreDeprecatedAndSplitIntoArguments(): void
    {
        $this->expectUserDeprecationMessageMatches('/extra_args as a string is deprecated/');

        [$command] = $this->runWithCommandCapture(['extra_args' => '--pretty-print --title "A B" \'C D\''], $this->fakeInputFile);

        $this->assertSame(['--pretty-print', '--title', 'A B', 'C D'], array_slice($command, 3));
    }

    public function testEmptyStringExtraArgsAddNothingAndAreNotDeprecated(): void
    {
        [$command] = $this->runWithCommandCapture(['extra_args' => ''], $this->fakeInputFile);

        $this->assertSame([$this->fakeCalibrePath, $this->fakeInputFile, $this->fakeOutputFile], $command);
    }

    public function testConvertsAnExtractedDirectoryThroughATemporaryEpub(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'calibre_dir_' . bin2hex(random_bytes(4));
        EpubBuilder::minimal()->writeTo($directory);
        $seenEpub = null;

        try {
            $this->runWithCommandCapture([], $directory, static function (array $command) use (&$seenEpub): void {
                $seenEpub = $command[1];
                TestCase::assertIsString($seenEpub);
                TestCase::assertStringEndsWith('.epub', $seenEpub);
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
        $this->helperMock->method('runProcess')->willReturn(['exitCode' => 0, 'output' => '']);

        $adapter = new CalibreAdapter(['calibre_path' => $this->fakeCalibrePath], $this->helperMock);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Calibre conversion failed');

        $adapter->convert($this->fakeInputFile, $this->fakeOutputFile);
    }

    /**
     * Expects one successful Calibre run and records the program it was started with.
     */
    private function captureProgram(): \stdClass
    {
        $program = new \stdClass();
        $program->value = null;

        $this->helperMock->expects($this->once())->method('runProcess')->willReturnCallback(
            static function (array $command) use ($program): array {
                $program->value = $command[0];

                return ['exitCode' => 0, 'output' => ''];
            }
        );

        return $program;
    }

    /**
     * Runs a successful conversion with a fake Calibre and returns the argv and timeout it ran with.
     *
     * @param array{calibre_path?: string, extra_args?: string|list<string>, timeout?: int|null} $options
     * @param (callable(array<mixed>): void)|null $onRun
     *
     * @return array{array<mixed>, int|null}
     */
    private function runWithCommandCapture(array $options, string $input, ?callable $onRun = null): array
    {
        $calibrePath = $options['calibre_path'] ?? $this->fakeCalibrePath;
        $helper = $this->helperMock;
        $helper->method('fileExists')->willReturnCallback(
            fn (string $path): bool => in_array($path, [$calibrePath, $this->fakeInputFile, $this->fakeOutputFile], true) || file_exists($path)
        );
        $helper->method('fileSize')->willReturn(100);

        $captured = [[], null];
        $helper->expects($this->once())->method('runProcess')->willReturnCallback(
            static function (array $command, ?int $timeout) use (&$captured, $onRun): array {
                $captured = [$command, $timeout];
                if ($onRun !== null) {
                    $onRun($command);
                }

                return ['exitCode' => 0, 'output' => ''];
            }
        );

        (new CalibreAdapter(['calibre_path' => $this->fakeCalibrePath, ...$options], $helper))->convert($input, $this->fakeOutputFile);

        return $captured;
    }
}
