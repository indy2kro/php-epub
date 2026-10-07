# php-epub improvement backlog — 2026-10-07 (round 3)

Discovery-only audit of `src/`, `scripts/`, `docs/`, `tests/` and `.github/workflows/`
at `27c1ef8` (main), after the round-2 backlog was closed (#121-#129).
No source changes were made while compiling this list.

## Gate results (fresh dependencies, `composer update`)

| Gate | Result |
|---|---|
| PHPUnit 12.5 (`XDEBUG_MODE=coverage`) | 389 tests, 4 skipped (Calibre, symlinks, read-only files); lines 99.5% |
| PHPCS `src/ tests/ docs/` | clean |
| PHPStan level max (512M) | clean |
| Rector `--dry-run` | clean |
| `composer audit` / `validate --strict` / `outdated --direct` | clean / valid / up to date |
| gitleaks | clean |
| actionlint | clean |

The remaining uncovered lines are defensive branches in `ZipHandler` and `FileSystemHelper`; no sweep item is needed for them.

## Summary

| Area | Items | High | Med | Low |
|---|---:|---:|---:|---:|
| SEC — untrusted input | 1 | 0 | 1 | 0 |
| BUG — wrong behaviour | 9 | 1 | 3 | 5 |
| CONV — converters | 3 | 0 | 1 | 2 |
| FEAT — new capability | 6 | 0 | 2 | 4 |
| UX — public API | 3 | 0 | 0 | 3 |
| DOC — documentation | 2 | 0 | 0 | 2 |
| TEST — tests | 2 | 0 | 1 | 1 |
| CI — workflows & packaging | 1 | 0 | 0 | 1 |
| SWEEP — cross-cutting | 1 | 0 | 0 | 1 |
| **Total** | **28** | **1** | **8** | **19** |

Effort: S = one file, under 30 min · M = one class plus tests · L = cross-cutting.

The target was 30 items; the audit found 28, and none were added just to reach the target.

## Execution instructions

- One branch and one PR per theme (see *Themes* below); stack PRs that touch the same files.
- Write the failing test first. Build hostile EPUBs inside the test (`tests/Support/EpubBuilder`)
  rather than committing binary fixtures.
- Before every push run the gates against fresh dependencies, as CI does
  (`composer update`, `composer quality`, then `git checkout composer.lock`), and check each exit code.
- Tick the box here in the same PR that fixes an item. Delete `docs/improvement-audit/` once every
  item is done or explicitly declined (list declined items in that PR's description).
- SEC-01 was flagged for information security review when found; fix it first.

### Themes

1. **Security hardening** — SEC-01
2. **Package integrity** — BUG-01, BUG-03, BUG-04, BUG-09, BUG-08, SWEEP-01
3. **Loading & extraction** — BUG-02, BUG-05, BUG-07
4. **Cover, metadata & spine** — BUG-06, UX-01, FEAT-01, FEAT-03, FEAT-04, UX-02, UX-03
5. **Converters** — CONV-01, CONV-02, CONV-03
6. **Validation, tests, CI, docs** — FEAT-02, TEST-01, TEST-02, CI-01, DOC-01, DOC-02
7. **Content features** — FEAT-05, FEAT-06

## Items

### Security (untrusted input)

- [x] **SEC-01** Confine image references inside SVG files and `data:` SVGs before PDF rendering — `src/Converters/EpubDocumentLoader.php:240-300`, `vendor/tecnickcom/tc-lib-pdf/src/SVG.php:7418-7500` — M · med
  The loader confines references in chapter HTML but passes book SVG files and `data:image/svg+xml` URIs to the renderer unexamined. tc-lib-pdf follows `<image href>` and nested `.svg` references, so with TCPDF a book's SVG can embed images from TCPDF's default read allowlist (the system temp dir holding other books, the working dir). Dompdf is chrooted to the book and is not affected.

### Bugs

- [x] **BUG-01** Keep IDPF-obfuscated fonts readable when the unique identifier changes (re-obfuscate, or refuse the change) — `src/Traits/InteractsWithIdentifier.php:35-49`, `src/EpubFile.php:236-247` — M · high
  Font obfuscation XORs fonts with a key derived from the unique identifier, and nothing reads `META-INF/encryption.xml`, so `setIdentifiers()` on such a book silently turns every embedded font into garbage.
- [x] **BUG-02** Open books with recoverable structure problems (an EPUB 3 book with a broken or navMap-less NCX, a missing or padded mimetype) and report them through `validate()` — `src/Parser.php:48-60,130-156` — M · med
  `Parser` throws on the first problem, so an EPUB 3 book whose optional legacy NCX is broken cannot be loaded or repaired with the library, although every reader opens it.
- [x] **BUG-03** Set the EPUB 3 manifest properties (`svg`, `mathml`, `scripted`, `remote-resources`) for XHTML added or updated through `ContentManager`/`addChapter()` — `src/ContentManager.php:89-130`, `src/EpubFile.php:162-188` — M · med
  EPUB 3 requires these properties on content that uses those features, so `addChapter()` with an inline `<svg>` or `<math>` body produces a book EPUBCheck rejects (OPF-014).
- [x] **BUG-04** Remove table-of-contents entries for files that `deleteContent()`/`Manifest::remove()` drop, and report dangling TOC links as errors — `src/ContentManager.php:135-160`, `src/Validator.php:180-200` — S · med
  A probe confirmed that a deleted chapter's nav/NCX entry stays, which EPUBCheck reports as an error (RSC-007), while `validate()` only warns.
- [x] **BUG-05** Do not let `load()` discard a book made with `create()` that was never saved — `src/EpubFile.php:104-145` — S · low
  `load()` re-extracts `$filePath`, which does not exist yet for an unsaved new book, so it throws and cleans up the work in progress.
- [x] **BUG-06** Check that cover bytes match the declared image media type — `src/EpubFile.php:302-335` — S · low
  `setCoverImage()` trusts the media type, so PNG bytes declared as `image/jpeg` produce a book that EPUBCheck and some readers reject. `getimagesizefromstring()` can detect the real type.
- [x] **BUG-07** Report entries that differ only by case when extracting on case-insensitive filesystems — `src/ZipHandler.php:46-90` — S · low
  On Windows and macOS `Text/a.xhtml` and `text/a.xhtml` extract to the same file, so one silently replaces the other.
- [x] **BUG-08** Update the EPUB 2 `opf:event="modification"` date on save, as `dcterms:modified` is for EPUB 3 — `src/Metadata.php:51-64` — S · low
  `getModifiedDate()` reports the EPUB 2 modification event, which `save()` never touches, so the date stays at its original value after edits.
- [x] **BUG-09** Update the NCX `docTitle` when the book title changes — `src/TableOfContents.php`, `src/Traits/InteractsWithTitle.php` — S · low
  `setTitle()` only changes the OPF, so readers that show the NCX `docTitle` keep the old name.

### Converters

- [ ] **CONV-01** Make TCPDF 7 render the images of books extracted outside its default read allowlist, or report that it cannot — `src/Converters/TCPDFAdapter.php:65-110`, `vendor/tecnickcom/tcpdf/tcpdf.php:491-520` — M · med
  TCPDF 7 only reads local files from the system temp dir, working dir, script dir and `K_ALLOWED_PATHS`, so `TCPDFAdapter::convert()` on a directory elsewhere (for example through `Converter`) silently drops every image.
- [ ] **CONV-02** Turn links between chapters into PDF-internal links — `src/Converters/EpubDocumentLoader.php:170-190` — M · low
  `<a href="chapter2.xhtml#note1">` is kept as written, so footnotes and cross-references are dead links in the PDF.
- [ ] **CONV-03** Render the cover image as the first PDF page when it is not in the spine — `src/Converters/EpubDocumentLoader.php:85-105` — S · low
  Many EPUB 3 books keep the cover only as a `cover-image` manifest item, so their PDFs start without a cover.

### Features

- [x] **FEAT-01** Series API that reads and writes both `calibre:series`/`calibre:series_index` and EPUB 3 `belongs-to-collection` (`collection-type` series, `group-position`) — `src/Metadata.php:94-200` — M · med
  Series is the most common metadata after title and author, and the two conventions disagree, so callers have to know about and update both by hand.
- [ ] **FEAT-02** Check XHTML content documents in `validate()`: well-formedness and local references (`img`, `link`, `a`) that are missing or not in the manifest — `src/Validator.php` — M · med
  Broken references are EPUBCheck's most common errors and `addChapter()` inserts caller markup as is, but `validate()` never looks inside content documents.
- [x] **FEAT-03** Access the remaining Dublin Core elements (rights, source, type, format, relation, coverage) and all languages — `src/Metadata.php:180-260`, `src/Traits/InteractsWithLanguage.php` — S · low
  Only eight DC elements have accessors and `getDcValues()` is protected, so `dc:rights` (licence text) and multilingual books cannot be handled.
- [x] **FEAT-04** Typed identifier lookup (`getIsbn()`, schemes from `opf:scheme`, `identifier-type` refinements and `urn:` prefixes) — `src/Traits/InteractsWithIdentifier.php:12-49` — S · low
  `getIdentifiers()` returns bare strings, so finding the ISBN means reimplementing the EPUB 2 and EPUB 3 scheme conventions.
- [ ] **FEAT-05** Rename or move content files and update every reference (manifest href, spine, table of contents, guide) — `src/ContentManager.php` — M · low
  Reorganising a book's files today means deleting and re-adding content, which loses the manifest id, spine position and TOC entries.
- [ ] **FEAT-06** Make manifest lookups fast for large books (index items by id and path) — `src/Manifest.php:74-110,330-360` — M · low
  A probe showed that adding 1,500 small files with `addContent()` takes 5.5 s, because every add rescans the manifest and every id check scans the whole package, which makes image-heavy books (comics) slow to build.

### Public API

- [x] **UX-01** Add `removeCoverImage()` and an option to delete the previous cover file — `src/EpubFile.php:302-335` — S · low
  Replacing a cover leaves the old image in the book, and a cover cannot be removed without manual manifest and meta edits.
- [x] **UX-02** Spine: `setLinear()` for existing entries, `page-progression-direction`, and bounds checks in `move()`/`add()` — `src/Spine.php:73-119` — S · low
  `linear` can only be set when adding, right-to-left books cannot be configured, and an out-of-range or negative position is silently clamped or counted from the end.
- [x] **UX-03** Mark `Manifest`/`Spine` `markSaved()` and `Metadata` `markModified()` as internal, or document them — `src/Manifest.php`, `src/Spine.php`, `src/Metadata.php:74-81` — S · low
  They are public only so `EpubFile` can coordinate saving, and calling them out of order makes `save()` skip writing the package.

### Documentation

- [ ] **DOC-01** Refresh `basic-usage.md` and `advanced-usage.md` for `open()`/`convert()`, `create()`/`addChapter()`, the table of contents and `validate()` — `docs/basic-usage.md`, `docs/advanced-usage.md:10-90` — S · low
  The guides still show `new EpubFile()` plus `load()` and converting an extracted directory directly, and never mention the newer, simpler APIs.
- [ ] **DOC-02** Document only the public `Parser` API, and document the `TableOfContents` `getBook()`/`isAvailable()` methods — `docs/parser.md:10-20`, `docs/table-of-contents.md` — S · low
  `parser.md` tells readers they can call `validateMimetype()`, `extractOpfPath()`, `validateOpf()` and `validateNcx()`, which are private, while two public `TableOfContents` methods are undocumented.

### Tests

- [ ] **TEST-01** Run EPUBCheck on saved EPUB 2 books too (NCX writing, `opf:role`/`opf:file-as`, guide) — `tests/EpubCheckTest.php`, `tests/Support/EpubBuilder.php` — S · med
  Every EPUBCheck scenario is EPUB 3, so the EPUB 2 writers (NCX navPoints, `opf:*` attributes, guide cleanup) are never checked against the validator.
- [ ] **TEST-02** Round-trip every fixture book through open, `validate()`, save and EPUBCheck — `tests/fixtures/valid_*.epub`, `tests/EpubFileTest.php:190-300` — S · low
  The six real-world fixtures are only loaded and saved; nothing checks that the library's output for them stays valid or that `validate()` agrees with EPUBCheck.

### CI

- [ ] **CI-01** Add `codecov.yml` with an explicit patch target and threshold — `.github/workflows/tests.yml` — S · low
  Without a config Codecov's patch target is the project's current coverage (about 99.5%), so a single unreachable defensive line fails a PR; three round-2 PRs needed workarounds for it.

### Sweeps

- [x] **SWEEP-01** Report write failures with an exception and no PHP warning in `XmlParser::save()`, `Metadata::save()` and `ContentManager::addContent()` — `src/XmlParser.php:73-85`, `src/Metadata.php:64`, `src/ContentManager.php:99` — S · low
  `asXML()` and `file_put_contents()` warn before the exception is thrown, which fails strict callers and test suites, while `updateContent()` and the rest of the library already report I/O failures without warnings.
