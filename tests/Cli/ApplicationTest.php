<?php

declare(strict_types=1);

namespace PhpEpub\Test\Cli;

use PhpEpub\Cli\Application;
use PhpEpub\EpubFile;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * The command line, run in-process: exit codes, output and the safety rules (limits, no overwrite).
 */
final class ApplicationTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_cli_' . bin2hex(random_bytes(8));
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->workDir);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} The exit code, standard output and standard error.
     */
    private function cli(array $arguments): array
    {
        $stdout = fopen('php://memory', 'w+b');
        $stderr = fopen('php://memory', 'w+b');
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);

        $code = (new Application($stdout, $stderr))->run($arguments);
        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    private function book(string $name = 'book.epub', ?EpubBuilder $builder = null): string
    {
        return ($builder ?? EpubBuilder::epub3())->buildEpub($this->workDir . '/' . $name);
    }

    public function testHelpListsTheCommands(): void
    {
        [$code, $out] = $this->cli(['--help']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Usage: php-epub', $out);
        $this->assertStringContainsString('merge', $out);
        $this->assertSame([0, $out], array_slice($this->cli(['validate', '--help']), 0, 2));
    }

    public function testUsageErrorsExitWithTwo(): void
    {
        foreach ([[], ['nope'], ['info'], ['info', 'a', 'b'], ['info', '--bogus', 'a'], ['validate', '--profile=x', 'a'], ['repair', 'a'], ['merge', 'a', '-o', 'x']] as $arguments) {
            [$code, $out, $err] = $this->cli($arguments);

            $this->assertSame(2, $code, implode(' ', $arguments));
            $this->assertSame('', $out);
            $this->assertStringStartsWith('php-epub: ', $err);
        }
    }

    public function testInfoPrintsTheSummaryAsJson(): void
    {
        [$code, $out] = $this->cli(['info', $this->book()]);

        $this->assertSame(0, $code);
        $summary = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('metadata', $summary);
        $this->assertSame(EpubFile::open($this->book('same.epub'))->toArray(), $summary);

        [, $compact] = $this->cli(['info', '--compact', $this->book()]);
        $this->assertStringNotContainsString("\n  ", $compact);
    }

    public function testInfoReadsAnExpandedDirectory(): void
    {
        $directory = $this->workDir . '/expanded';
        mkdir($directory);
        EpubBuilder::epub3()->writeTo($directory);

        [$code, $out] = $this->cli(['info', $directory]);

        $this->assertSame(0, $code);
        $this->assertSame(EpubFile::openDirectory($directory)->toArray(), json_decode($out, true));
    }

    public function testAMissingOrBrokenBookExitsWithThree(): void
    {
        [$code, , $err] = $this->cli(['info', $this->workDir . '/missing.epub']);
        $this->assertSame(3, $code);
        $this->assertStringContainsString('does not exist', $err);

        file_put_contents($this->workDir . '/junk.epub', 'not a zip');
        $this->assertSame(3, $this->cli(['validate', $this->workDir . '/junk.epub'])[0]);
    }

    public function testValidateExitsWithOneOnErrorsAndZeroOtherwise(): void
    {
        $good = $this->book();
        [$code, $out] = $this->cli(['validate', $good]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('No problems found.', $out);

        $broken = $this->book('broken.epub', EpubBuilder::epub3()->withoutFile('EPUB/text/chapter.xhtml'));
        [$code, $out] = $this->cli(['validate', $broken]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('MANIFEST_FILE_MISSING', $out);

        [$code, $json] = $this->cli(['validate', '--json', $broken]);
        $this->assertSame(1, $code);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['valid']);
        $this->assertGreaterThan(0, $decoded['errors']);
    }

    public function testStrictMakesWarningsFail(): void
    {
        $path = $this->book('fixture.epub');
        $this->assertSame(0, $this->cli(['validate', __DIR__ . '/../fixtures/valid.epub'])[0]);
        $this->assertSame(1, $this->cli(['validate', '--strict', __DIR__ . '/../fixtures/valid.epub'])[0]);
        $this->assertSame(0, $this->cli(['validate', '--strict', $path])[0]);
    }

    public function testValidateAppliesAProfile(): void
    {
        [, $out] = $this->cli(['validate', '--profile=kindle', $this->book()]);

        $this->assertStringContainsString('fix:', $out);
    }

    public function testRepairWritesAFixedCopy(): void
    {
        $broken = $this->book('broken.epub', EpubBuilder::epub3()->withoutFile('EPUB/text/chapter.xhtml'));
        $output = $this->workDir . '/fixed.epub';

        [$code, $out] = $this->cli(['repair', $broken, '-o', $output]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('MANIFEST_FILE_MISSING', $out);
        $this->assertStringNotContainsString('MANIFEST_FILE_MISSING', $this->cli(['validate', $output])[1]);
        $this->assertStringContainsString('MANIFEST_FILE_MISSING', $this->cli(['validate', $broken])[1], 'the input is untouched');

        [$code, , $err] = $this->cli(['repair', $broken, '--fix=nope', '-o', $this->workDir . '/other.epub']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Unknown repair', $err);

        [$code, $out] = $this->cli(['repair', $broken, '--fix=missing-files,spine-references', '-o', $this->workDir . '/some.epub']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('MANIFEST_FILE_MISSING', $out);
    }

    public function testNothingIsOverwrittenWithoutForce(): void
    {
        $book = $this->book();
        $output = $this->workDir . '/out.epub';
        file_put_contents($output, 'precious');

        [$code, , $err] = $this->cli(['compress', $book, '-o', $output]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('--force', $err);
        $this->assertSame('precious', file_get_contents($output));

        $this->assertSame(0, $this->cli(['compress', $book, '-o', $output, '--force'])[0]);
        $this->assertSame(0, $this->cli(['validate', $output])[0]);
    }

    public function testTheOutputCanNeverBeTheInput(): void
    {
        $book = $this->book();
        $before = file_get_contents($book);

        foreach ([['repair', $book, '-o', $book, '--force'], ['compress', $book, '-o', $book, '--force'], ['convert', $book, '-o', $book, '--to=txt', '--force'], ['merge', $book, $book, '-o', $book, '--force']] as $arguments) {
            [$code, , $err] = $this->cli($arguments);
            $this->assertSame(2, $code, $arguments[0]);
            $this->assertStringContainsString('must not be the input', $err);
        }

        $this->assertSame($before, file_get_contents($book));
    }

    public function testCompressReportsAndHonoursDryRun(): void
    {
        $book = $this->book('stray.epub', EpubBuilder::epub3()->withFile('EPUB/unused.txt', str_repeat('x', 5000)));
        $output = $this->workDir . '/small.epub';

        [$code, $out] = $this->cli(['compress', '--dry-run', $book]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('dry run', $out);

        [$code, $out] = $this->cli(['compress', '--preset=light', $book, '-o', $output]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Archive:', $out);
        $this->assertFileExists($output);

        [$code, , $err] = $this->cli(['compress', '--preset=wild', $book, '-o', $this->workDir . '/x.epub']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Unknown preset', $err);
    }

    public function testConvertExportsTextHtmlAndMarkdown(): void
    {
        $book = $this->book();

        foreach (['txt', 'html', 'md'] as $extension) {
            $output = $this->workDir . '/out.' . $extension;
            [$code] = $this->cli(['convert', $book, '-o', $output]);

            $this->assertSame(0, $code, $extension);
            $this->assertNotSame('', (string) file_get_contents($output));
        }

        $other = $this->workDir . '/other.out';
        $this->assertSame(0, $this->cli(['convert', $book, '--to', 'txt', '-o', $other])[0]);
        $this->assertSame(2, $this->cli(['convert', $book, '-o', $this->workDir . '/noformat.dat'])[0]);
        $this->assertSame(2, $this->cli(['convert', $book, '--to=docx', '-o', $this->workDir . '/x.docx'])[0]);
        $this->assertSame(2, $this->cli(['convert', $book, '--to=pdf', '--engine=wkhtml', '-o', $this->workDir . '/x.pdf'])[0]);
    }

    public function testConvertToPdfNeedsAnInstalledEngine(): void
    {
        if (! class_exists(\Dompdf\Dompdf::class)) {
            $this->markTestSkipped('dompdf is not installed.');
        }

        $output = $this->workDir . '/out.pdf';

        $this->assertSame(0, $this->cli(['convert', $this->book(), '-o', $output, '--engine=dompdf'])[0]);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($output));
    }

    public function testSplitWritesParts(): void
    {
        $book = $this->book('nested.epub', EpubBuilder::nestedToc());
        $directory = $this->workDir . '/parts';

        [$code, $out] = $this->cli(['split', $book, '-d', $directory, '--every=1', '--prefix=chunk']);

        $this->assertSame(0, $code);
        $paths = array_filter(explode("\n", $out));
        $this->assertNotSame([], $paths);
        foreach ($paths as $path) {
            $this->assertFileExists($path);
            $this->assertStringContainsString('chunk-', $path);
        }

        [$code, , $err] = $this->cli(['split', $book, '-d', $directory, '--every=1', '--prefix=chunk']);
        $this->assertSame(3, $code, 'existing parts are not overwritten');
        $this->assertStringContainsString('exist', $err);

        $this->assertSame(2, $this->cli(['split', $book, '--every=1'])[0]);
        $this->assertSame(2, $this->cli(['split', $book, '-d', $directory])[0]);
        $this->assertSame(2, $this->cli(['split', $book, '-d', $directory, '--every=1', '--toc'])[0]);
        $this->assertSame(2, $this->cli(['split', $book, '-d', $directory, '--every=abc'])[0]);
        $this->assertSame(0, $this->cli(['split', $book, '-d', $this->workDir . '/bytes', '--max-bytes=100000'])[0]);
        $this->assertSame(0, $this->cli(['split', $book, '-d', $this->workDir . '/toc', '--toc', '--level=1'])[0]);
    }

    public function testMergeJoinsBooks(): void
    {
        $first = $this->book('a.epub');
        $second = $this->book('b.epub', EpubBuilder::epub2());
        $output = $this->workDir . '/merged.epub';

        [$code, $out] = $this->cli(['merge', $first, $second, '-o', $output, '--title=Both', '--author=Ann', '--author=Bob', '--language=en', '--flat']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Merged 2 books', $out);
        $merged = EpubFile::open($output);
        $this->assertSame('Both', $merged->getMetadata()->getTitle());
        $this->assertSame(['Ann', 'Bob'], $merged->getMetadata()->getAuthors());
        $this->assertSame(0, $this->cli(['validate', $output])[0]);
    }

    public function testBooksAreOpenedWithWebLimitsUnlessTrusted(): void
    {
        // 2,001 entries: over Limits::web() (2,000) and within Limits::default() (10,000).
        $builder = EpubBuilder::epub3();
        for ($index = 0; $index < 2001; $index++) {
            $builder = $builder->withFile("EPUB/extra/{$index}.txt", 'x');
        }

        $book = $this->book('many.epub', $builder);

        [$code, , $err] = $this->cli(['info', $book]);
        $this->assertSame(3, $code);
        $this->assertStringContainsString('too many entries', $err);

        $this->assertSame(3, $this->cli(['validate', $book])[0]);
        $this->assertSame(0, $this->cli(['info', '--trusted', $book])[0]);
        $this->assertSame(0, $this->cli(['validate', '--trusted', '--json', $book])[0]);
    }

    public function testDrmBooksAreRefusedByConversionAndSplit(): void
    {
        $book = $this->book('drm.epub', EpubBuilder::epub3()->withFile('META-INF/license.lcpl', '{}'));

        $this->assertSame(3, $this->cli(['convert', $book, '-o', $this->workDir . '/drm.txt'])[0]);
        $this->assertSame(3, $this->cli(['split', $book, '-d', $this->workDir . '/drm', '--every=1'])[0]);

        [$code, $out] = $this->cli(['info', '--compact', $book]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"isDrmProtected":true', $out);
    }

    public function testTheBinScriptRunsTheApplication(): void
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/../../bin/php-epub', 'validate', $this->book()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process));
        $this->assertSame("No problems found.
", $out);
    }
}
