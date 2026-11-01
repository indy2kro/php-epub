# Command line

Composer installs a small command line, `vendor/bin/php-epub`, for CI pipelines and publishing scripts that have no PHP to write. It is a thin layer over the library (`EpubReader`, `EpubFile`, `Merger`, `Splitter`) with no dependencies of its own.

```
vendor/bin/php-epub <command> [options] <arguments>
```

A `<book>` is an `.epub` file or an expanded (unzipped) book directory (see `EpubFile::openDirectory()`).

## Commands

| Command | What it does |
|---|---|
| `info <book>` | Prints the JSON summary of `toArray()` (metadata, cover, table of contents, reading order, statistics, DRM state). Reads an archive without extracting it, through `EpubReader`. `--compact` prints one line. |
| `validate <book>` | Runs `validate()` and prints the issues. `--profile=kindle` adds the Kindle checks, `--json` prints `{valid, errors, warnings, issues}`, `--strict` makes warnings fail too. |
| `repair <book> -o <out>` | Applies the repairs of `EpubFile::repair()` and saves the result. `--fix=missing-files,cover` applies only those repairs (the `RepairFix` values; without it all but `upgrade-to-epub3`). |
| `compress <book> -o <out>` | Shrinks the book with `EpubFile::compress()`. `--preset=light\|balanced\|strong` (default `balanced`); `--dry-run` only reports and needs no output. |
| `convert <book> -o <out>` | Exports the book as `txt`, `html`, `md` or `pdf`. `--to=` names the format (default: the output's extension); `--engine=dompdf\|tcpdf` picks the PDF library, which must be installed. |
| `split <book> -d <dir>` | Cuts the book into parts with `Splitter`: `--toc` (with `--level=<n>`), `--every=<items>` or `--max-bytes=<bytes>`; `--prefix=<name>` names the files. Prints the part paths. |
| `merge <book> <book>... -o <out>` | Joins two or more books with `Merger`. `--title=`, `--author=` (repeatable), `--language=`, `--flat` (no table of contents section per book). |
| `build <source> -o <out>` | Makes a book from a Markdown, text or HTML file with [`BookBuilder`](book-builder.md). `--from=markdown\|text\|html` (default: from the extension `.md`, `.txt`, `.html`), `--title=` (default: the file name), `--author=` (repeatable), `--language=`, `--identifier=`, `--cover=<image>`, `--css=<file>`, `--split-level=<1-6>`, and `--images=<dir>`: the JPEG, PNG, GIF and WebP files of that directory, which the source refers to by file name. No other file is read. Prints the output and its number of chapters; builder warnings go to standard error. |
| `meta <book>` | Prints the metadata as JSON (`title`, `subtitle`, `authors`, `language`, `identifier`, `isbn`, `publisher`, `description`, `date`, `rights`, `cover`). With `--title=`, `--author=` (repeatable, replaces the authors), `--language=`, `--identifier=` (the unique identifier) or `--cover=<image>` and `-o <out>`, saves a copy with those changes; the book itself is never changed. |

Options that every command accepts: `-o`/`--output`, `--force`, `--trusted`, `-h`/`--help`. Calibre conversions are not offered; use the library for them.

```
vendor/bin/php-epub validate book.epub --strict
vendor/bin/php-epub info book.epub | jq '.metadata.titles'
vendor/bin/php-epub repair broken.epub -o fixed.epub
vendor/bin/php-epub compress book.epub --preset=strong -o small.epub
vendor/bin/php-epub convert book.epub -o book.md
vendor/bin/php-epub split book.epub -d parts --every=10
vendor/bin/php-epub merge one.epub two.epub -o both.epub --title="Both"
vendor/bin/php-epub build novel.md -o novel.epub --title="The Novel" --author="Ann Author" --cover=cover.jpg --images=images
vendor/bin/php-epub meta book.epub
vendor/bin/php-epub meta book.epub -o fixed.epub --title="The Right Title" --cover=cover.png
```

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Success (for `validate`: no errors, and with `--strict` no warnings) |
| `1` | `validate` found problems, including a book it cannot read at all (not an EPUB, no readable package document, over a limit): that is reported as one `EPUB_UNREADABLE` error, also in `--json` |
| `2` | The command line is wrong: unknown command or option, missing argument, an output that exists without `--force`, an output that is the input |
| `3` | The operation failed: the input does not exist, the book is not an EPUB or exceeds a limit (every command but `validate`), is DRM-protected (`convert`, `split`, `merge`), a file cannot be written |

Messages name the files of a book by their path inside it (`META-INF/container.xml`, `EPUB/package.opf`), never by the temporary directory the book was extracted to, so they are safe to show to the person who uploaded it.

Results go to standard output, errors and warnings to standard error, prefixed with `php-epub:`.

## Untrusted books

The command line treats every book as untrusted input. Books are opened with [`Limits::web()`](epub-reader.md) (2,000 entries, 200 MiB, 8 MiB per XML or XHTML document), so a hostile archive is refused instead of filling the disk: with exit code `3`, or for `validate` as an `EPUB_UNREADABLE` error with exit code `1`. `--trusted` switches to `Limits::default()` for books you made yourself.

Nothing is overwritten without `--force`, and an output can never be the book it is made from: `repair`, `compress`, `convert` and `merge` always write a new file.

## From PHP

The same command is available in-process, which is how the tests run it:

```php
use PhpEpub\Cli\Application;

$stdout = fopen('php://memory', 'w+b');
$stderr = fopen('php://memory', 'w+b');

$exitCode = (new Application($stdout, $stderr))->run(['validate', '/path/to/book.epub', '--json']);
```

`Application::run()` takes the command line without the script name and returns the exit code (`Application::SUCCESS`, `PROBLEMS_FOUND`, `USAGE`, `FAILURE`); without streams it writes to the process's standard output and error.
