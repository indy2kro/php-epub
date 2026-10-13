<?php

declare(strict_types=1);

namespace PhpEpub\Cli;

use PhpEpub\Cleanup\CleanupOptions;
use PhpEpub\Cleanup\CleanupPreset;
use PhpEpub\Cleanup\CleanupReport;
use PhpEpub\Converters\ConverterInterface;
use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\Converters\HtmlAdapter;
use PhpEpub\Converters\MarkdownAdapter;
use PhpEpub\Converters\TCPDFAdapter;
use PhpEpub\Converters\TextAdapter;
use PhpEpub\EpubFile;
use PhpEpub\EpubReader;
use PhpEpub\Exception;
use PhpEpub\Limits;
use PhpEpub\Merge\MergeOptions;
use PhpEpub\Merge\Merger;
use PhpEpub\Repair\RepairFix;
use PhpEpub\Repair\RepairOptions;
use PhpEpub\Split\Splitter;
use PhpEpub\Split\SplitPlan;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ValidationIssue;
use PhpEpub\ValidationProfile;

/**
 * The php-epub command line: a thin layer over the library's entry points (EpubReader, EpubFile, Merger, Splitter).
 *
 * Every book is treated as untrusted: it is opened with Limits::web() unless --trusted asks for Limits::default().
 * Nothing is overwritten without --force, and an output can never be the book it was made from.
 *
 * Exit codes: 0 success, 1 the check found problems (validate), 2 wrong usage, 3 the operation failed.
 */
final class Application
{
    public const int SUCCESS = 0;

    public const int PROBLEMS_FOUND = 1;

    public const int USAGE = 2;

    public const int FAILURE = 3;

    private const string USAGE_TEXT = <<<'TEXT'
        Usage: php-epub <command> [options] <arguments>

        Commands:
          info <book>                       Print a JSON summary of the book (read without extracting it)
          validate <book>                   Check the book; exit code 1 when it has errors
          repair <book> -o <out>            Fix the problems that can be fixed automatically
          compress <book> -o <out>          Make the book smaller
          convert <book> -o <out>           Export the book as txt, html, md or pdf
          split <book> -d <dir>             Cut the book into parts
          merge <book> <book>... -o <out>   Join books into one

        A <book> is an .epub file or an expanded (unzipped) book directory.

        Common options:
          --trusted     Use the library's default limits instead of the stricter Limits::web() (for books you made yourself)
          --force       Overwrite an output that exists
          -o, --output  The output file
          -h, --help    Show this help

        validate:  --profile=kindle  --json  --strict (warnings fail too)
        repair:    --fix=<name>[,<name>]  Only these repairs (see RepairFix; default: all but upgrade-to-epub3)
        compress:  --preset=light|balanced|strong  --dry-run (report only, no output)
        convert:   --to=txt|html|md|pdf (default: from the output's extension)  --engine=dompdf|tcpdf (pdf only)
        split:     -d, --directory <dir>  --toc [--level=<n>] | --every=<items> | --max-bytes=<bytes>  --prefix=<name>
        merge:     --title=<title>  --author=<name> (repeatable)  --language=<tag>  --flat (no section per book)

        Exit codes: 0 success, 1 problems found, 2 wrong usage, 3 the operation failed.

        TEXT;

    /**
     * @var resource
     */
    private $stdout;

    /**
     * @var resource
     */
    private $stderr;

    /**
     * @param resource|null $stdout Where results are written (standard output by default).
     * @param resource|null $stderr Where errors are written (standard error by default).
     */
    public function __construct($stdout = null, $stderr = null)
    {
        $this->stdout = $stdout ?? fopen('php://stdout', 'wb') ?: throw new Exception('Cannot open standard output');
        $this->stderr = $stderr ?? fopen('php://stderr', 'wb') ?: throw new Exception('Cannot open standard error');
    }

    /**
     * Runs a command.
     *
     * @param list<string> $arguments The command line without the script name.
     *
     * @return int The exit code.
     */
    public function run(array $arguments): int
    {
        try {
            return $this->dispatch($arguments);
        } catch (UsageException $exception) {
            $this->write($this->stderr, "php-epub: {$exception->getMessage()}\nRun \"php-epub --help\" for usage.\n");

            return self::USAGE;
        } catch (Exception $exception) {
            $this->write($this->stderr, "php-epub: {$exception->getMessage()}\n");

            return self::FAILURE;
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @throws Exception
     */
    private function dispatch(array $arguments): int
    {
        $command = $arguments[0] ?? throw new UsageException('No command given');
        if (in_array($command, ['-h', '--help', 'help'], true)) {
            $this->write($this->stdout, self::USAGE_TEXT);

            return self::SUCCESS;
        }

        $rest = array_slice($arguments, 1);
        if (in_array('--help', $rest, true) || in_array('-h', $rest, true)) {
            $this->write($this->stdout, self::USAGE_TEXT);

            return self::SUCCESS;
        }

        return match ($command) {
            'info' => $this->info($rest),
            'validate' => $this->validate($rest),
            'repair' => $this->repair($rest),
            'compress' => $this->compress($rest),
            'convert' => $this->convert($rest),
            'split' => $this->split($rest),
            'merge' => $this->merge($rest),
            default => throw new UsageException("Unknown command: {$command}"),
        };
    }

    /**
     * @param list<string> $arguments
     *
     * @throws Exception
     */
    private function info(array $arguments): int
    {
        $input = Arguments::parse($arguments, [], ['trusted', 'compact']);
        $path = $this->single($input, 'a book');
        $limits = $this->limits($input);

        if (is_dir($path)) {
            $book = EpubFile::openDirectory($path, limits: $limits);
            try {
                $summary = $book->toArray();
            } finally {
                $book->close();
            }
        } else {
            $reader = EpubReader::open($path, $limits);
            try {
                $summary = $reader->toArray();
            } finally {
                $reader->close();
            }
        }

        $this->json($summary, $input->flag('compact'));

        return self::SUCCESS;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws Exception
     */
    private function validate(array $arguments): int
    {
        $input = Arguments::parse($arguments, ['profile'], ['trusted', 'json', 'strict']);
        $path = $this->single($input, 'a book');
        $profile = match ($input->value('profile')) {
            null => null,
            'kindle' => ValidationProfile::kindle(),
            default => throw new UsageException('Unknown profile: ' . $input->value('profile') . ' (known: kindle)'),
        };

        $book = $this->open($path, $this->limits($input));
        try {
            $issues = $book->validate($profile);
        } finally {
            $book->close();
        }

        $errors = count(array_filter($issues, static fn (ValidationIssue $issue): bool => $issue->severity === ValidationIssue::ERROR));
        $warnings = count($issues) - $errors;
        $failed = $errors > 0 || ($input->flag('strict') && $warnings > 0);

        if ($input->flag('json')) {
            $this->json([
                'valid' => ! $failed,
                'errors' => $errors,
                'warnings' => $warnings,
                'issues' => array_map(static fn (ValidationIssue $issue): array => [
                    'severity' => $issue->severity,
                    'code' => $issue->code,
                    'message' => $issue->message,
                    'location' => $issue->location,
                    'fix' => $issue->fix,
                ], $issues),
            ], false);
        } else {
            foreach ($issues as $issue) {
                $this->write($this->stdout, $issue . "\n" . ($issue->fix === null ? '' : "  fix: {$issue->fix}\n"));
            }

            $this->write($this->stdout, $issues === [] ? "No problems found.\n" : "{$errors} error(s), {$warnings} warning(s)\n");
        }

        return $failed ? self::PROBLEMS_FOUND : self::SUCCESS;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws Exception
     */
    private function repair(array $arguments): int
    {
        $input = Arguments::parse($arguments, ['output', 'fix'], ['trusted', 'force']);
        $path = $this->single($input, 'a book');
        $output = $this->output($input, [$path]);

        $fixes = [];
        foreach ($input->values('fix') as $names) {
            foreach (explode(',', $names) as $name) {
                $fixes[] = RepairFix::tryFrom(trim($name)) ?? throw new UsageException(
                    "Unknown repair: {$name} (known: " . implode(', ', array_map(static fn (RepairFix $fix): string => $fix->value, RepairFix::cases())) . ')'
                );
            }
        }

        $book = $this->open($path, $this->limits($input));
        try {
            $applied = $book->repair($fixes === [] ? null : RepairOptions::only(...$fixes));
            $book->save($output);
        } finally {
            $book->close();
        }

        foreach ($applied as $fix) {
            $this->write($this->stdout, $fix . "\n");
        }

        $this->write($this->stdout, ($applied === [] ? 'No repairs needed' : count($applied) . ' repair(s) applied') . ". Saved: {$output}\n");

        return self::SUCCESS;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws Exception
     */
    private function compress(array $arguments): int
    {
        $input = Arguments::parse($arguments, ['output', 'preset'], ['trusted', 'force', 'dry-run']);
        $path = $this->single($input, 'a book');
        $dryRun = $input->flag('dry-run');
        $output = $dryRun ? null : $this->output($input, [$path]);
        $preset = CleanupPreset::tryFrom($input->value('preset') ?? 'balanced') ?? throw new UsageException('Unknown preset: ' . $input->value('preset') . ' (known: light, balanced, strong)');

        $book = $this->open($path, $this->limits($input));
        try {
            $report = $dryRun ? $book->compress(CleanupOptions::preset($preset)->withDryRun()) : $book->compress($preset, $output);
        } finally {
            $book->close();
        }

        $this->printCleanup($report);
        $output === null || $this->write($this->stdout, "Saved: {$output}\n");

        return self::SUCCESS;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws Exception
     */
    private function convert(array $arguments): int
    {
        $input = Arguments::parse($arguments, ['output', 'to', 'engine'], ['trusted', 'force']);
        $path = $this->single($input, 'a book');
        $output = $this->output($input, [$path]);
        $format = $input->value('to') ?? match (strtolower(pathinfo($output, PATHINFO_EXTENSION))) {
            'txt' => 'txt',
            'html', 'htm' => 'html',
            'md', 'markdown' => 'md',
            'pdf' => 'pdf',
            default => throw new UsageException('Give the format with --to=txt|html|md|pdf (it cannot be told from the output name)'),
        };

        $converter = match ($format) {
            'txt' => new TextAdapter(),
            'html' => new HtmlAdapter(),
            'md' => new MarkdownAdapter(),
            'pdf' => $this->pdfAdapter($input->value('engine')),
            default => throw new UsageException("Unknown format: {$format} (known: txt, html, md, pdf)"),
        };

        $book = $this->open($path, $this->limits($input));
        try {
            $book->convert($converter, $output);
        } finally {
            $book->close();
        }

        $this->write($this->stdout, "Saved: {$output}\n");

        return self::SUCCESS;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws Exception
     */
    private function split(array $arguments): int
    {
        $input = Arguments::parse($arguments, ['directory', 'every', 'max-bytes', 'level', 'prefix'], ['trusted', 'toc'], ['d' => 'directory', 'h' => 'help']);
        $path = $this->single($input, 'a book');
        $directory = $input->value('directory') ?? throw new UsageException('Give the output directory with -d <dir>');
        $directory !== '' || throw new UsageException('The output directory must not be empty');

        $strategies = array_filter([$input->flag('toc') ? 'toc' : null, $input->value('every') !== null ? 'every' : null, $input->value('max-bytes') !== null ? 'max-bytes' : null]);
        count($strategies) === 1 || throw new UsageException('Choose one of --toc, --every=<items> or --max-bytes=<bytes>');

        $plan = match (array_values($strategies)[0]) {
            'toc' => SplitPlan::byToc($this->integer($input->value('level'), 'level', 1)),
            'every' => SplitPlan::everySpineItems($this->integer($input->value('every'), 'every')),
            default => SplitPlan::byMaxBytes($this->integer($input->value('max-bytes'), 'max-bytes')),
        };
        $prefix = $input->value('prefix');
        $prefix === null || $plan = $plan->withFilePrefix($prefix);

        $book = $this->open($path, $this->limits($input));
        try {
            $report = (new Splitter())->splitWithReport($book, $plan, $directory);
        } finally {
            $book->close();
        }

        foreach ($report->paths as $part) {
            $this->write($this->stdout, $part . "\n");
        }

        foreach ($report->warnings as $warning) {
            $this->write($this->stderr, "warning: {$warning}\n");
        }

        return self::SUCCESS;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws Exception
     */
    private function merge(array $arguments): int
    {
        $input = Arguments::parse($arguments, ['output', 'title', 'author', 'language'], ['trusted', 'force', 'flat']);
        count($input->positionals) >= 2 || throw new UsageException('Give at least two books to merge');
        $output = $this->output($input, $input->positionals);
        $limits = $this->limits($input);
        $options = new MergeOptions(
            title: $input->value('title'),
            authors: $input->values('author') === [] ? null : $input->values('author'),
            language: $input->value('language'),
            oneSectionPerBook: ! $input->flag('flat')
        );

        $books = [];
        try {
            foreach ($input->positionals as $path) {
                $books[] = $this->open($path, $limits);
            }

            $report = (new Merger())->merge($books, $options, $output);
        } finally {
            foreach ($books as $book) {
                try {
                    $book->close();
                    // Closing only fails when the scratch directory cannot be deleted; cannot be provoked portably.
                    // @codeCoverageIgnoreStart
                } catch (Exception) {
                    // The merge's outcome stands; a leftover scratch directory is not worth hiding it.
                }

                // @codeCoverageIgnoreEnd
            }
        }

        foreach ($report->warnings as $warning) {
            $this->write($this->stderr, "warning: {$warning}\n");
        }

        $this->write($this->stdout, "Merged {$report->books} books, {$report->files} files. Saved: {$output}\n");

        return self::SUCCESS;
    }

    private function printCleanup(CleanupReport $report): void
    {
        foreach ($report->actions as $action) {
            $this->write($this->stdout, sprintf(
                "%s: %d file(s), %d -> %d bytes%s\n",
                $action->name,
                count($action->files),
                $action->bytesBefore,
                $action->bytesAfter,
                $action->skipped ? " (skipped: {$action->note})" : ''
            ));
        }

        $this->write($this->stdout, "Files: {$report->bytesBefore} -> {$report->bytesAfter} bytes" . ($report->dryRun ? ' (dry run, nothing written)' : '') . "\n");
        if ($report->archiveBytesAfter !== null) {
            $this->write($this->stdout, 'Archive: ' . ($report->archiveBytesBefore ?? '?') . " -> {$report->archiveBytesAfter} bytes\n");
        }
    }

    /**
     * @throws Exception If the PDF library is not installed.
     */
    private function pdfAdapter(?string $engine): ConverterInterface
    {
        $engine ??= class_exists(\Dompdf\Dompdf::class) ? 'dompdf' : 'tcpdf';

        return match ($engine) {
            'dompdf' => class_exists(\Dompdf\Dompdf::class) ? new DompdfAdapter() : throw new Exception('PDF output with dompdf needs dompdf/dompdf (composer require dompdf/dompdf)'),
            'tcpdf' => class_exists(\TCPDF::class) ? new TCPDFAdapter() : throw new Exception('PDF output with tcpdf needs tecnickcom/tcpdf (composer require tecnickcom/tcpdf)'),
            default => throw new UsageException("Unknown engine: {$engine} (known: dompdf, tcpdf)"),
        };
    }

    /**
     * Every book is untrusted unless the caller says otherwise.
     */
    private function limits(Arguments $input): Limits
    {
        return $input->flag('trusted') ? Limits::default() : Limits::web();
    }

    private function open(string $path, Limits $limits): EpubFile
    {
        return is_dir($path) ? EpubFile::openDirectory($path, limits: $limits) : EpubFile::open($path, limits: $limits);
    }

    /**
     * @throws UsageException If there is not exactly one positional argument.
     */
    private function single(Arguments $input, string $what): string
    {
        count($input->positionals) === 1 || throw new UsageException("Give {$what}");

        return $input->positionals[0];
    }

    /**
     * The output file: given, not the same as an input, and not existing unless --force.
     *
     * @param list<string> $inputs
     *
     * @throws UsageException
     */
    private function output(Arguments $input, array $inputs): string
    {
        $output = $input->value('output') ?? throw new UsageException('Give the output file with -o <file>');
        $output !== '' || throw new UsageException('The output path must not be empty');

        foreach ($inputs as $path) {
            ! file_exists($path) || ! FileSystemHelper::isSameFile($path, $output) || throw new UsageException("The output must not be the input: {$output}");
        }

        ! file_exists($output) || $input->flag('force') || throw new UsageException("The output exists (use --force to overwrite it): {$output}");

        return $output;
    }

    /**
     * @throws UsageException
     */
    private function integer(?string $value, string $option, ?int $default = null): int
    {
        if ($value === null || $value === '') {
            return $default ?? throw new UsageException("The option --{$option} needs a number");
        }

        return ctype_digit($value) ? (int) $value : throw new UsageException("The option --{$option} needs a whole number, got: {$value}");
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data, bool $compact): void
    {
        $this->write($this->stdout, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($compact ? 0 : JSON_PRETTY_PRINT)) . "\n");
    }

    /**
     * @param resource $stream
     */
    private function write($stream, string $text): void
    {
        fwrite($stream, $text);
    }
}
