# php-epub improvement backlog — 2026-11-01 (round 6)

Discovery-only audit of `src/`, `bin/`, `scripts/`, `docs/`, `tests/` and `.github/workflows/`
at `ed9f611` (`feat/round5-backlog`: the round-5 implementation, not yet merged into `main`).
No source changes were made while compiling this list.

## Gate results (fresh dependencies, `composer update`)

| Gate | Result |
|---|---|
| PHPUnit 12.5.42 | 1720 tests, 20 skipped (environment: Windows file names, symlinks, `ZipArchive::openString()`, EPUBCheck not configured) |
| PHPUnit with Xdebug coverage (1G) | **1 failure**: `MergerTest::testMergingThousandsOfFilesStaysFast` took 13.7 s against an 8 s budget (TEST-03); lines 99.79% (7459/7475) |
| PHPCS `src/ tests/ docs/ bin/` | clean |
| PHPStan 2.3.4 level max (512M) | clean |
| Rector 2.8.3 `--dry-run` | clean |
| `composer audit` / `validate --strict` / `outdated --direct` | clean / valid / up to date |
| gitleaks (569 commits) | clean |
| actionlint | clean |

The 16 uncovered lines are the symlink-only branches of `FileSystemHelper`, `ZipHandler` and `EpubFile::copyTree()`, plus
the four round-5 paths in SWEEP-03. The `epubcheck` group was not run locally (no `EPUBCHECK_JAR`).

Besides the gates and the code review, behavioural probes were run against the seven real-world fixtures. They
round-tripped metadata, the TOC, landmarks and the page list. They also covered EPUB 2 → 3 upgrades, merges,
`compress()`, moves, deletes, the exports, the Kindle profile, both PDF engines and the CLI. Those probes turned up
BUG-01 to BUG-04, CONV-01, FEAT-01 and SWEEP-01/02.

## Summary

| Area | Items | High | Med | Low |
|---|---:|---:|---:|---:|
| SEC — untrusted input | 1 | 0 | 0 | 1 |
| BUG — wrong behaviour | 4 | 1 | 3 | 0 |
| CONV — converters | 3 | 1 | 1 | 1 |
| FEAT — new capability | 8 | 0 | 5 | 3 |
| UX — public API | 3 | 0 | 1 | 2 |
| DOC — documentation | 1 | 0 | 0 | 1 |
| TEST — tests | 3 | 0 | 2 | 1 |
| CI — pipeline | 1 | 0 | 0 | 1 |
| SWEEP — cross-cutting | 3 | 0 | 2 | 1 |
| **Total** | **27** | **2** | **14** | **11** |

Effort: S = one file, under 30 min · M = one class plus tests · L = cross-cutting.

The target was 30. Only 27 items held up after checking, and the list was not padded. No security vulnerabilities
were found. ZIP extraction and directory import, `PathResolver`, `XmlParser`, the stream buffering cap, Dompdf's
sandbox options, Calibre's shell-free process call and the pinned font downloads were re-checked. SEC-01 is
hardening. Theme 1 (BUG-01 to BUG-04, UX-02) and Theme 2 (CONV-01, CONV-02, CONV-03, TEST-02) have been fully completed.

## Execution instructions

- One branch and one PR per theme (see *Themes* below); stack PRs that touch the same files.
- Write the failing test first. Build hostile EPUBs inside the test (`tests/Support/EpubBuilder`)
  rather than committing binary fixtures.
- Before every push run the gates against fresh dependencies, as CI does
  (`composer update`, `composer quality`, then `git checkout composer.lock`), and check each exit code.
- Tick the box here in the same PR that fixes an item. Delete `docs/improvement-audit/` once every
  item is done or explicitly declined (list declined items in that PR's description).

### Themes

1. **Package integrity**: BUG-01, BUG-02, BUG-03, BUG-04, UX-02
2. **Converters**: CONV-01, CONV-02, CONV-03, TEST-02
3. **Validation & repair**: SWEEP-01, FEAT-01, FEAT-02, FEAT-03
4. **Editing API**: FEAT-04, FEAT-06, FEAT-07, UX-01, UX-03
5. **CLI, building & errors**: SWEEP-02, FEAT-05, FEAT-08
6. **Hardening, tests, CI & docs**: SEC-01, SWEEP-03, TEST-01, TEST-03, CI-01, DOC-01

## Items

### Sweeps

- [ ] **SWEEP-01** Add the package and content rules EPUBCheck enforces to `validate()`: duplicate element ids in a document, no linear spine item, more than one `cover-image` item, invalid `rendition:*` values — `src/Validator.php`, `src/Util/Rendition.php`, `src/Metadata.php:189-230` — M · med
  Probe: each of these four books validates clean, and `setProperty('rendition:layout', 'banana')` is accepted too. EPUBCheck rejects all four (RSC-005, OPF-033, cover-image cardinality, the rendition vocabulary).
- [ ] **SWEEP-02** Name book-relative paths, not the private extraction directory, in exception messages, and let `php-epub validate` report an unreadable book as a problem (exit 1, an issue in `--json`) instead of a failure — `src/Parser.php:35-44`, `src/XmlParser.php:29-44`, `src/EpubFile.php:403-410`, `src/ContentManager.php:164-175`, `src/Cli/Application.php:191-240` — M · med
  Probe: `php-epub validate tests/fixtures/invalid.epub` exits 3 with `XML file not found: C:\…\Temp\epub_<hex>\META-INF\container.xml`, and a broken OPF reports the same temp path. A web service that echoes the message leaks its temp layout, and the user cannot tell which part of the book is wrong.
- [ ] **SWEEP-03** Cover the remaining uncovered lines: NCX `navList` renumbering, `Cleanup` dropping a removed font's encryption entry, `EpubDocumentLoader` with a damaged `encryption.xml`, the `saveToDirectory()` rollback — `src/TableOfContents.php:889`, `src/Cleanup/Cleanup.php:353`, `src/Converters/EpubDocumentLoader.php:447-448`, `src/EpubFile.php:470-475` — S · low
  Apart from the symlink-only branches, these are round-5 behaviours (ed9f611's `navList` renumbering among them) that no test exercises.

### Security hardening

- [ ] **SEC-01** Remove (or make private) the unused public `FileSystemHelper::exec()`, which runs a string through the shell — `src/Util/FileSystemHelper.php:16-26`, `tests/Util/FileSystemHelperTest.php:209` — S · low
  Nothing in `src/` has called it since `CalibreAdapter` moved to `runProcess()`. A public shell-exec helper in a library that handles hostile input is a foot-gun for callers. This is hardening, not a vulnerability.

### Bugs

- [x] **BUG-01** Fix `Manifest::add()`/`addMany()` (and the guide's `addChild()`) on package documents that use an `opf:` prefix: index the new item from a re-queried node and write it unprefixed — `src/Manifest.php:141-162`, `src/Manifest.php:177-217`, `src/EpubFile.php:337-365`, `src/EpubFile.php:677-715` — M · high
  Probe: `valid_6.epub` (Project Gutenberg, `xmlns` plus `xmlns:opf`) and a fully prefixed `<opf:package>` both trigger it. SimpleXML's `addChild()` creates an `<opf:item>` whose unprefixed attributes the returned node cannot read. `add()` therefore returns an item with id `''` and the OPF directory as its path. `addChapter()` then throws "The chapter is not in the manifest". `setCoverImage()` saves a package with a duplicated `media-type` attribute, which `EpubFile::open()` can no longer load. Metadata and spine edits on the same books are fine.
- [x] **BUG-02** Make `setCoverImage()` repoint the cover page (the guide cover or first spine page `<img>`) at the new image, and never delete an image that content still references — `src/EpubFile.php:677-715`, `src/EpubFile.php:825-832` — M · med
  Probe: `setCoverImage($png, 'image/png', null, true)` on the fixtures leaves CONTENT_REFERENCE_MISSING in 4 of 6 books (`valid`, `valid_2`, `valid_3`, `valid_6`), because the cover XHTML still points at the deleted image. Without `deletePrevious` the cover page keeps showing the old cover.
- [x] **BUG-03** Make `deleteContent()` remove or unwrap the links and embeds in other documents that point at the deleted file (`LinkRemover`), or report them — `src/ContentManager.php:157-188`, `src/Util/LinkRemover.php` — M · med
  Probe: deleting the third spine document of each fixture leaves CONTENT_REFERENCE_MISSING in `valid_2`, `valid_4` and `valid_6` (cross-links from other chapters). The TOC, guide, spine, manifest and encryption entries are all cleaned up.
- [x] **BUG-04** Make `addChapter()` linear: remember the next free chapter number and append TOC entries without re-reading and re-writing the whole navigation each time (or add `addChapters()`) — `src/EpubFile.php:337-383`, `src/TableOfContents.php:360` — M · med
  Probe: adding 1,500 chapters to a new book takes 31 s, and each batch of 500 is slower than the last (6.7 s, 10.4 s, 14.1 s). `unusedChapterPath()` probes from `chapter-1` every time, and each `addEntry()` rewrites the nav and the NCX.
### Converters

- [x] **CONV-01** Stop `TCPDFAdapter` from failing the whole book on CSS values TCPDF 7 rejects (`width:auto`, `height:auto`): drop or normalise them before `writeHTML()` — `src/Converters/TCPDFAdapter.php:232-250`, `src/Converters/EpubDocumentLoader.php`, `src/Util/CssSanitizer.php` — S · high
  With fresh dependencies (`tecnickcom/tcpdf` 7.0.20, `tc-lib-pdf` 8.82.2), converting `valid_4` and `valid_6` throws `Invalid value: auto` from `HTML::parseHTMLStyleWidthProperty`. A one-chapter book with `<p style="width:auto">` fails as well. Dompdf renders the same books.
- [x] **CONV-02** Route the `Converter` class through `EpubFile::convert()` (accept an `EpubFile`, or deprecate the directory form), and make the first example in `docs/converter.md` use `$epub->convert()` — `src/Converter.php:19-44`, `docs/converter.md:15-36` — S · med
  The headline example passes `$epubFile->getTempDir()` to `Converter`. That skips `EpubFile::convert()`'s DRM refusal, the book's `Limits` (`LimitsAwareConverter`) and `writePackage()`, so unsaved edits are not converted and an untrusted book is rendered without its caps.
- [x] **CONV-03** Plan for TCPDF 7's deprecation: mark `TCPDFAdapter` as legacy in the docs and evaluate an adapter on `tecnickcom/tc-lib-pdf`, or recommend Dompdf as the default PDF engine — `src/Converters/TCPDFAdapter.php`, `composer.json` (`suggest`), `docs/converters/tcpdf-adapter.md` — M · low
  Packagist now describes `tecnickcom/tcpdf` 7.0.20 as "Deprecated legacy PDF engine for PHP. Use instead tecnickcom/tc-lib-pdf". Its compatibility shim is where the CONV-01 regression appeared.
- [x] **TEST-02** Convert every real-world fixture with each adapter (Text, HTML, Markdown, Dompdf, TCPDF) in the test suite — `tests/Converters/`, `tests/fixtures/valid*.epub` — S · med
  CONV-01 (TCPDF fails on 2 of 3 real books) passes CI because the converter tests only use synthetic books. A smoke test over `tests/fixtures` would have caught it when `tc-lib-pdf` changed.

### Features

- [ ] **FEAT-01** Check references in stylesheets (`url()`, `@import`, `<style>`, `style` attributes) and the fragments of navigation links (TOC, landmarks, page list) in `validate()` — `src/Validator.php:334-377`, `src/Util/FragmentReferences.php`, `src/Cleanup/ReferenceGraph.php` — M · med
  Probe: a stylesheet with `url(../img/nope.png)` and a TOC entry pointing at `#nofrag` both validate clean, while EPUBCheck reports RSC-007 and RSC-012. `ReferenceGraph` already parses CSS references.
- [ ] **FEAT-02** Validate manifest `fallback` and `media-overlay` references: unknown ids, fallback cycles, chains that never reach a content document, overlays that are not SMIL — `src/Validator.php:320-330`, `src/Validator.php:847-850`, `src/Manifest.php:224-237`, `src/Manifest.php:559-580` — M · med
  `hasFallback()` only checks that a `fallback` attribute exists, and `setFallback()`/`setMediaOverlay()` accept cycles. Books that EPUBCheck rejects (OPF-040/045, MED-xxx) pass `validate()`.
- [ ] **FEAT-03** Add a `Repairer` fix for CONTENT_REFERENCE_MISSING: unwrap links and drop embeds that point at files missing from the book — `src/Repair/RepairFix.php:10-58`, `src/Repair/Repairer.php`, `src/Util/LinkRemover.php` — M · med
  Probe: after deleting a chapter of `valid_4`, `repair()` applies 1 fix and 6 CONTENT_REFERENCE_MISSING errors remain. `FragmentLinks` handles missing `#fragments` only, although `LinkRemover` already does the unwrapping.
- [x] **FEAT-04** Add `TableOfContents::setPageList()`, which writes the nav `page-list` and the NCX `pageList` together (e.g. generated from `epub:type="pagebreak"` markers) — `src/TableOfContents.php:320-378`, `src/Validator.php:644-660` — M · med
  The page list is read-only, yet `validate()` warns ACCESSIBILITY_PAGE_LIST_MISSING for books with a print source, and the European Accessibility Act expects page navigation. Callers cannot fix a warning the library raises.
- [ ] **FEAT-05** Add CLI commands for building and editing: `build` (`BookBuilder` from Markdown, text or HTML) and `meta` (print or set the title, authors, language, identifier and cover) — `src/Cli/Application.php:143-149`, `docs/cli.md:11-34`, `src/Build/BookBuilder.php` — M · med
  The CLI covers inspect, repair, convert, split and merge. Making a book from Markdown and fixing a title or a cover, the two most common publishing chores, still need PHP code.
- [x] **FEAT-06** Add a DOM-level editing helper, e.g. `ContentManager::transform(callable(DOMDocument, string $path): bool)` over the XHTML documents, that writes back only the documents that changed — `src/ContentManager.php:294-330`, `src/Cleanup/MarkupSanitizer.php:105-130` — M · low
  Batch edits (fix a class, add `lang`, replace a word) now take `getContent()`, a string replace and `updateContent()` per file. The library already has the parse, size-cap and serialise steps internally (`MarkupSanitizer::transform()`).
- [x] **FEAT-07** Let `EpubReader` copy an entry to a stream (`copyContentTo($path, $stream)`) under the same limits, instead of only returning strings — `src/EpubReader.php:464-478`, `src/EpubReader.php:604-660` — S · low
  Serving a cover, an image or a 100 MB audio file of a read-aloud book from an upload means holding it in a PHP string, although `read()` already streams the entry chunk by chunk.
- [ ] **FEAT-08** Turn Markdown footnotes (`[^1]` / `[^1]: …`) into EPUB 3 notes in `BookBuilder` (`epub:type` `noteref` and `footnote`, with backlinks) — `src/Build/MarkdownParser.php`, `src/Build/BookBuilder.php:61-73` — M · low
  Probe: footnote syntax comes out as literal `[^1]` text. Footnotes are standard in Pandoc and GFM manuscripts, and reading systems show `epub:type` notes as pop-ups.

### Public API

- [x] **UX-01** Add metadata helpers for typed identifiers (`setIsbn()`, or an identifier with a scheme), the subtitle (`title-type`) and rights — `src/Traits/InteractsWithIdentifier.php:40-66`, `src/Traits/InteractsWithTitle.php:13-51`, `src/Metadata.php:271-300` — M · med
  `getIsbn()`/`getTypedIdentifiers()` read schemes, but `setIdentifiers()` takes plain strings and drops `opf:scheme`/`identifier-type` on changed values. Adding the ISBN a store requires, a subtitle or a copyright line needs raw `setDublinCoreValues()` or hand-written refinement XML.
- [x] **UX-02** Let `EpubFile::create()` take `Limits` / `ZipHandler` / `XmlParser` like `open()` — `src/EpubFile.php:300-325` — S · low
  A new book is always saved with the default `ZipHandler` and an uncapped `XmlParser`. Tests cannot inject collaborators, and a service cannot apply its limits to content added later.
- [x] **UX-03** Make `Merger::merge()`'s `MergeOptions` optional, and align it with `Splitter` (`split()`/`splitWithReport()` versus `merge()` returning the report) — `src/Merge/Merger.php:75`, `src/Split/Splitter.php:64-74` — S · low
  `merge($books, null, $out)` is a TypeError although every `MergeOptions` field has a default, and the two sibling APIs return their reports differently.

### Documentation

- [ ] **DOC-01** Document `SplitKind` and `EpubDocument` (both appear in public signatures), mark `TextEncoding` and `XhtmlFragment` `@internal`, and document or mark `@internal` the remaining public methods `CleanupOptions::withDryRun()`, `CleanupReport::withArchiveSizes()`, `PdfConversionOptions::deadline()`, `EpubDocument::outlineByChapter()`, `EpubDocumentLoader::anchorId()` and `Parser::locatePackage()` — `src/Split/SplitKind.php`, `src/Converters/EpubDocument.php`, `src/Util/TextEncoding.php`, `src/Util/XhtmlFragment.php`, `docs/split.md`, `docs/converter.md` — S · low
  These are the only public classes and methods that are neither named in `docs/` or `README.md` nor `@internal`, so it is unclear whether semver covers them.

### Tests

- [ ] **TEST-01** Add real-world fixtures (e.g. the IDPF epub3-samples, CC BY-SA) for fixed layout, media overlays, RTL and CJK vertical text, obfuscated fonts and SVG, and run them through the EPUBCheck round-trip — `tests/fixtures/`, `tests/EpubCheckTest.php` — M · med
  Carried over: round-5 TEST-01 is still open. All 7 committed books are reflowable Latin-script books without fonts, overlays, SVG or a page-progression direction. The EPUB 3 features most likely to break on save are only exercised by synthetic `EpubBuilder` books, and neither of the two OPF namespace layouts behind BUG-01 is covered by a test.
- [ ] **TEST-02** Convert every real-world fixture with each adapter (Text, HTML, Markdown, Dompdf, TCPDF) in the test suite — `tests/Converters/`, `tests/fixtures/valid*.epub` — S · med
  CONV-01 (TCPDF fails on 2 of 3 real books) passes CI because the converter tests only use synthetic books. A smoke test over `tests/fixtures` would have caught it when `tc-lib-pdf` changed.
- [x] **TEST-03** Replace the wall-clock assertion in `MergerTest::testMergingThousandsOfFilesStaysFast` with an operation count, or a budget that allows for coverage — `tests/Merge/MergerTest.php:557-580` — S · low
  Under Xdebug coverage the test took 13.7 s against an 8 s budget and failed. A local coverage run, or any slow CI runner, reports a false regression.

### CI

- [ ] **CI-01** Add a CI job without the optional extensions (`gd`, `intl`, `iconv`), drop the unused `ext-curl` from `require-dev`, and suggest `ext-intl` — `.github/workflows/tests.yml:45-50`, `composer.json` (`require-dev`, `suggest`), `src/ZipHandler.php:332-339`, `src/Util/TextEncoding.php:57-60`, `src/Cleanup/ImageRecompressor.php:34` — S · low
  Every job installs `gd`, and `intl` comes with the runners. The no-GD `compress()` path, the ASCII case-folding fallback and the `iconv` fallback therefore never run in CI, while nothing in `src/` or `tests/` calls `curl`.
