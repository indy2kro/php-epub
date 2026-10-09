# AI Agent Instructions

This document provides instructions for AI agents working on this codebase.

php-epub (`indy2kro/php-epub`) is a PHP library for reading, editing, creating, validating and converting EPUB 2/3
books. Namespace `PhpEpub\` → `src/`, tests `PhpEpub\Test\` → `tests/`.

## PHP Version

- Minimum: PHP 8.3 (`config.platform.php` in `composer.json` is pinned to 8.3.0)
- Current development: PHP 8.5

## Code Quality

**Always run code quality tools after making changes. Run them in this order** (`composer quality` runs all four):

```bash
# 1. Run PHPUnit tests first
vendor/bin/phpunit

# 2. Run PHPCS for code style
vendor/bin/phpcs src/ tests/ docs/

# 3. Run PHPStan for static analysis
php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress

# 4. Run Rector (review its suggestions, don't apply them blindly)
vendor/bin/rector --dry-run
```

- PHPUnit fails on deprecations, notices, warnings, risky and incomplete tests (skips are allowed).
- PHPStan runs at `level: max` over `src` and `tests`.
- PHPCS is PSR-12 (line length unchecked) over `src`, `tests` and the PHP files in `docs/`.
- Rector runs on `src/` and `tests/` but skips `src/EpubFile.php` entirely, because its suggestions there (e.g.
  `NewInInitializerRector`'s nullable parameters) conflict with PHPStan.
- CI runs `composer update` before the gates, so it may use newer PHPStan/Rector than a stale `vendor/`.
- `composer install/update` runs `scripts/generate-core-fonts.php`, which downloads and generates the TCPDF 7 core
  font files the TCPDF converter needs.

## Architecture

See `docs/architecture.md` for the full overview.

`EpubFile` is the facade: `open()`/`load()` extract the archive into a private temp directory and parse the OPF
**once**; `Metadata`, `Manifest` and `Spine` all edit that same in-memory OPF document, and `save()` writes it back
once and repacks. `create()` starts a new book, `convert()` runs a converter on the book including unsaved edits, and
`cleanup()` (also called from the destructor) removes the extraction.

- `Metadata` composes one trait per field from `src/Traits/` (`InteractsWithTitle`, `InteractsWithAuthors`, …, plus
  `UpgradesToEpub3`). A new metadata field gets a new trait.
- `Manifest` converts between hrefs (relative to the OPF) and paths (relative to the book root); removing an item also
  clears references to it elsewhere in the package.
- `ContentManager` does file-level edits on the extracted book while keeping manifest and spine in sync; moving a file
  rewrites references via `Util\ReferenceRewriter`. It never edits the OPF directly.
- `TableOfContents` edits the EPUB 3 nav `toc` and the EPUB 2 NCX together (both when a book has both), plus the
  landmarks / `<guide>` and the page list.
- `Parser` locates and checks the OPF via `META-INF/container.xml`. Problems that reading systems tolerate (a wrong
  `mimetype`, a broken NCX) are not thrown but reported by `Validator` through `EpubFile::validate()` as
  `ValidationIssue`s.
- `Build\BookBuilder` makes a new book from Markdown, text, HTML or images; its input is untrusted, so HTML goes through
  `Util\HtmlSanitizer` (allowlist, images only from the supplied map, no SVG) and `Build\BuildLimits` bounds the work.
- `Encryption` and `FontObfuscation` handle `META-INF/encryption.xml` and IDPF/Adobe font obfuscation.
- Converters (`src/Converters/`): `TCPDFAdapter` and `DompdfAdapter` render through `EpubDocumentLoader`, which reads
  the spine documents and makes their HTML safe to render; `CalibreAdapter` runs `ebook-convert`; `TextAdapter`, `HtmlAdapter`
  and `MarkdownAdapter` export text, sanitised HTML and Markdown from the loader's output (the HTML and Markdown go through
  `Util\HtmlSanitizer`, an allowlist). `Converter` maps formats to adapters.
- Core classes accept their collaborators as optional constructor arguments, which the tests use:

  ```php
  $epub = new EpubFile($path, $mockZipHandler, $mockXmlParser);
  ```

### Untrusted input

Every byte of a book is treated as hostile. Preserve these invariants:

- `Util\PathResolver` keeps every path (from the book and from callers) inside the extraction directory.
- `ZipHandler` enforces extraction limits (entry count, total size, compression ratio) and writes OCF-valid archives
  (`mimetype` first and stored uncompressed, `/` separators).
- `XmlParser` loads XML without network access and rejects entity declarations.
- `EpubDocumentLoader` (with `ConfinedTcpdf`) confines everything the PDF renderers could load to the book.
- Only `PhpEpub\Exception` subclasses may escape: `ZipException`, `InvalidEpubException` (and its subclass
  `XmlException`), `ConversionException` and `BuildException`. Methods also throw when used incorrectly (e.g. `getMetadata()` before
  `load()`).

## Testing

- Single test: `vendor/bin/phpunit tests/EpubFileTest.php --filter testName`
- Hostile and edge-case books are built in the tests with `tests/Support/EpubBuilder` instead of being committed as
  binary fixtures; `tests/fixtures/valid*.epub` are real-world books.
- Some tests skip depending on the environment (Calibre not installed, no symlink permission, read-only files,
  EPUBCheck not configured). `tests/Converters/CalibreAdapterRealTest.php` needs `ebook-convert`; CI runs it in the
  `calibre` job with `--fail-on-skipped`.
- `tests/EpubCheckTest.php` (group `epubcheck`) saves edited EPUB 3 and EPUB 2 books and the real-world fixtures and,
  when `EPUBCHECK_JAR` points at `epubcheck.jar`, validates them with EPUBCheck (a fixture's saved copy may not add
  errors its original lacks) (`EPUBCHECK_JAVA` overrides the java binary). CI runs it
  in the `epubcheck` job: `EPUBCHECK_JAR=/path/to/epubcheck.jar vendor/bin/phpunit --group epubcheck`
- `tests/DocumentationExamplesTest.php` extracts every fenced `php` block from `README.md` and `docs/**/*.md`, checks
  that it parses, that the `PhpEpub\...` classes it imports exist and that every method it calls is a public method
  of a class in `src/`. Blocks that are API signatures (starting with `public`) are wrapped automatically; a block
  that is deliberately a fragment gets `<!-- example:skip -->` on the line above its opening fence. The failure
  names the file, the line of the block and the problem. API changes must update the docs, and new API docs need
  examples that pass this test.
- `tests/FuzzTest.php` (group `fuzz`) mutates valid books (truncation, byte flips, odd or duplicated entry names,
  corrupted container/OPF/NCX/nav/XHTML) and feeds them to `ZipHandler::extract()`, `EpubFile::open()` +
  `validate()`, `XmlParser::parseString()` and `EpubDocumentLoader::load()`. Only `PhpEpub\Exception` subclasses
  are allowed; warnings, notices and deprecations fail. `EPUB_FUZZ_SEED` (fixed by default), `EPUB_FUZZ_ITERATIONS`
  (default 40) and `EPUB_FUZZ_FIRST` control it, and a failure prints the seed and the command to reproduce it. The
  `fuzz` CI job runs it with 5000 iterations on the weekly schedule and on `workflow_dispatch`.

## Documentation and releases

- The docs are an MkDocs site (`mkdocs.yml`, `docs/`) deployed to `gh-pages` by `.github/workflows/deploy-docs.yml`.
- There is no CHANGELOG file; release notes are written in GitHub releases.

## Improvement audits

`.claude/skills/project-improvement-audit/SKILL.md` describes the project-wide audit: harvest the quality gates, review
the code in module batches, and write a prioritized checkbox backlog to `docs/improvement-audit/` (discovery only, no
source changes). Backlog items are then implemented one theme per branch and PR, and `docs/improvement-audit/` is
removed once every item is done or declined.
