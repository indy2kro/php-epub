<?php

declare(strict_types=1);

namespace PhpEpub\Cli;

use PhpEpub\Build\BookBuilder;
use PhpEpub\Build\BookOptions;
use PhpEpub\Build\BuildLimits;
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
use PhpEpub\InvalidEpubException;
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
use PhpEpub\ZipException;

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

    /**
     * Source file extensions build understands, and the format each means.
     */
    private const array SOURCE_FORMATS = ['md' => 'markdown', 'markdown' => 'markdown', 'txt' => 'text', 'text' => 'text', 'html' => 'html', 'htm' => 'html', 'xhtml' => 'html'];

    /**
     * The image files build takes from an --images directory.
     */
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

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
          build <source> -o <out>           Make a book from Markdown, text or HTML
          meta <book> [-o <out>]            Print the metadata, or save a copy with changed metadata

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
        build:     --from=markdown|text|html (default: from the extension)  --title=  --author= (repeatable)
                   --language=  --identifier=  --cover=<image>  --images=<dir>  --css=<file>  --split-level=<1-6>
        meta:      --title=  --author= (repeatable)  --language=  --identifier=  --cover=<image>  (need -o)

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
            'build' => $this->build($rest),
            'meta' => $this->meta($rest),
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

        // A book that cannot be read at all is a problem of the book (exit 1), not a failure; a missing input is.
        $book = null;
        $issues = [];
        try {
            $book = $this->open($path, $this->limits($input));
        } catch (InvalidEpubException | ZipException $exception) {
            file_exists($path) || throw $exception;
            $issues = [new ValidationIssue(ValidationIssue::ERROR, 'EPUB_UNREADABLE', "The book cannot be read: {$exception->getMessage()}")];
        }

        if ($book instanceof EpubFile) {
            try {
                $issues = $book->validate($profile);
            } finally {
                $book->close();
            }
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
     * @param list<string> $arguments
     *
     * @throws Exception
     */
    private function build(array $arguments): int
    {
        $input = Arguments::parse(
            $arguments,
            ['output', 'from', 'title', 'author', 'language', 'identifier', 'cover', 'images', 'css', 'split-level'],
            ['trusted', 'force']
        );
        $source = $this->single($input, 'a Markdown, text or HTML file');
        $output = $this->output($input, [$source]);
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $from = $input->value('from')
            ?? self::SOURCE_FORMATS[$extension]
            ?? throw new UsageException("Cannot tell the format of {$source}: give --from=markdown|text|html");
        in_array($from, ['markdown', 'text', 'html'], true) || throw new UsageException("Unknown format: {$from} (known: markdown, text, html)");
        $splitLevel = $this->integer($input->value('split-level'), 'split-level', 1);
        ($splitLevel >= 1 && $splitLevel <= 6) || throw new UsageException("The option --split-level needs 1 to 6, got: {$splitLevel}");

        $limits = new BuildLimits();
        $images = [];
        $imageBytes = 0;
        $directory = $input->value('images');
        if ($directory !== null) {
            is_dir($directory) || throw new UsageException("Not a directory: {$directory}");
            foreach (scandir($directory) ?: [] as $name) {
                $file = $directory . DIRECTORY_SEPARATOR . $name;
                if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true) && is_file($file)) {
                    // The limits apply before the bytes are held, so a large directory cannot exhaust the memory.
                    count($images) < $limits->maxImages || throw new Exception("Too many images in {$directory}: the limit is {$limits->maxImages}");
                    $imageBytes += (int) @filesize($file);
                    $imageBytes <= $limits->maxTotalBytes || throw new Exception("The images in {$directory} are larger than the limit of {$limits->maxTotalBytes} bytes");
                    $images[$name] = $this->readFile($file, $limits->maxImageBytes);
                }
            }
        }

        $cover = $input->value('cover');
        $css = $input->value('css');
        $title = $input->value('title') ?? pathinfo($source, PATHINFO_FILENAME);
        $options = new BookOptions(
            title: $title === '' ? 'Untitled' : $title,
            authors: $input->values('author'),
            language: $input->value('language') ?? 'en',
            identifier: $input->value('identifier'),
            coverImage: $cover === null ? null : $this->readFile($this->optionFile($cover, 'cover'), $limits->maxImageBytes),
            css: $css === null ? '' : $this->readFile($this->optionFile($css, 'css'), $limits->maxTotalBytes),
            images: $images,
            splitLevel: $splitLevel,
            limits: $limits
        );

        $text = $this->readFile($source, $limits->maxTotalBytes);
        $builder = new BookBuilder($options);
        $book = match ($from) {
            'markdown' => $builder->fromMarkdown($text),
            'text' => $builder->fromText($text),
            default => $builder->fromHtml($text),
        };

        foreach ($book->warnings as $warning) {
            $this->write($this->stderr, "php-epub: warning: {$warning}\n");
        }

        $book->save($output);
        $this->write($this->stdout, "Saved: {$output} ({$book->chapterCount} chapters)\n");

        return self::SUCCESS;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws Exception
     */
    private function meta(array $arguments): int
    {
        $input = Arguments::parse($arguments, ['output', 'title', 'author', 'language', 'identifier', 'cover'], ['trusted', 'force', 'compact']);
        $path = $this->single($input, 'a book');
        $changes = array_filter(['title', 'author', 'language', 'identifier', 'cover'], static fn (string $name): bool => $input->values($name) !== []);

        if ($changes === []) {
            $input->value('output') === null || throw new UsageException('Give the metadata to change: --title, --author, --language, --identifier or --cover');
            $this->json($this->metadataSummary($path, $this->limits($input)), $input->flag('compact'));

            return self::SUCCESS;
        }

        $output = $this->output($input, [$path]);
        foreach (['title', 'language', 'identifier'] as $name) {
            $value = $input->value($name);
            $value === null || trim($value) !== '' || throw new UsageException("The option --{$name} must not be empty");
        }

        $cover = null;
        $coverPath = $input->value('cover');
        if ($coverPath !== null) {
            $bytes = $this->readFile($this->optionFile($coverPath, 'cover'), (new BuildLimits())->maxImageBytes);
            $info = @getimagesizefromstring($bytes);
            $type = $info === false ? '' : $info['mime'];
            in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
                || throw new UsageException("The cover must be a JPEG, PNG, GIF or WebP image: {$coverPath}");
            $cover = [$bytes, $type];
        }

        $book = $this->open($path, $this->limits($input));
        try {
            $metadata = $book->getMetadata();
            $input->value('title') === null || $metadata->setTitle((string) $input->value('title'));
            $input->values('author') === [] || $metadata->setAuthors($input->values('author'));
            $input->value('language') === null || $metadata->setLanguage((string) $input->value('language'));
            $identifier = $input->value('identifier');
            if ($identifier !== null) {
                $unique = $metadata->getUniqueIdentifier();
                $others = array_values(array_filter($metadata->getIdentifiers(), static fn (string $id): bool => $id !== $unique && $id !== $identifier));
                $metadata->setIdentifiers([$identifier, ...$others]);
            }

            $cover === null || $book->setCoverImage($cover[0], $cover[1]);
            $book->save($output);
        } finally {
            $book->close();
        }

        $this->write($this->stdout, "Saved: {$output}\n");

        return self::SUCCESS;
    }

    /**
     * The metadata the meta command prints.
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function metadataSummary(string $path, Limits $limits): array
    {
        $book = is_dir($path) ? EpubFile::openDirectory($path, limits: $limits) : EpubReader::open($path, $limits);
        try {
            $metadata = $book->getMetadata();

            return [
                'title' => $metadata->getTitle(),
                'authors' => $metadata->getAuthors(),
                'language' => $metadata->getLanguage(),
                'identifier' => $metadata->getUniqueIdentifier(),
                'isbn' => $metadata->getIsbn(),
                'publisher' => $metadata->getPublisher(),
                'description' => $metadata->getDescription(),
                'date' => $metadata->getDate(),
                'cover' => $book->getCoverImage()?->path,
            ];
        } finally {
            $book->close();
        }
    }

    /**
     * A file named by an option, which must exist.
     *
     * @throws UsageException
     */
    private function optionFile(string $path, string $option): string
    {
        return is_file($path) ? $path : throw new UsageException("The file for --{$option} does not exist: {$path}");
    }

    /**
     * Reads a file the user named, refusing one larger than $maxBytes before reading it.
     *
     * @throws Exception If the file does not exist, is too large or cannot be read.
     */
    private function readFile(string $path, int $maxBytes): string
    {
        is_file($path) || throw new Exception("The file does not exist: {$path}");
        $size = @filesize($path);
        ($size !== false && $size <= $maxBytes) || throw new Exception("The file is larger than {$maxBytes} bytes: {$path}");

        return FileSystemHelper::readFile($path) ?? throw new Exception("Failed to read: {$path}");
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
