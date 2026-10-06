# php-epub improvement backlog — 2026-10-06

Discovery-only audit of `src/`, `scripts/`, `docs/`, `tests/` and `.github/workflows/`
at `af4bbcc` (main). No source changes were made while compiling this list.

## Gate results (fresh dependencies, `composer update`)

| Gate | Result |
|---|---|
| PHPUnit 12.5 (`XDEBUG_MODE=coverage`) | 95 tests, 3 skipped (Calibre); lines 88.95%, methods 52.46% |
| PHPCS `src/ tests/ docs/` | clean |
| PHPStan level max (512M) | clean |
| Rector `--dry-run` | clean |
| `composer audit` / `validate --strict` / `outdated --direct` | clean / valid / up to date |
| gitleaks | clean |
| actionlint | 2 × SC2086 (unquoted `$GITHUB_OUTPUT`) |

## Summary

| Area | Items | High | Med | Low |
|---|---:|---:|---:|---:|
| SEC — untrusted input | 9 | 1 | 5 | 3 |
| BUG — wrong behaviour | 11 | 3 | 6 | 2 |
| CONV — converters | 6 | 1 | 2 | 3 |
| UX — public API | 4 | 0 | 1 | 3 |
| FEAT — new capability | 3 | 0 | 2 | 1 |
| DOC — documentation | 2 | 0 | 1 | 1 |
| TEST — tests | 3 | 1 | 0 | 2 |
| CI — workflows | 3 | 0 | 1 | 2 |
| SWEEP — cross-cutting | 2 | 0 | 2 | 0 |
| **Total** | **43** | **6** | **20** | **17** |

Effort: S = one file, under 30 min · M = one class plus tests · L = cross-cutting.

## Execution instructions

- One branch and one PR per theme (see *Themes* below); stack PRs that touch the same files.
- Write the failing test first. Build hostile EPUBs inside the test (`tests/Support/EpubBuilder`)
  rather than committing binary fixtures.
- Before every push run the gates against fresh dependencies, as CI does
  (`composer update`, `composer quality`, then `git checkout composer.lock`), and check each exit code.
- Tick the box here in the same PR that fixes an item. Delete `docs/improvement-audit/` once every
  item is done or explicitly declined (list declined items in that PR's description).

### Themes

1. **OCF-valid save** — BUG-01, BUG-02, TEST-01, CI-01
2. **Path confinement** — SEC-01, SEC-02, SEC-08, BUG-07, BUG-08
3. **Untrusted ZIP/XML hardening** — SEC-03, SEC-07, SEC-09, UX-03
4. **Metadata round-trip** — SWEEP-01, BUG-03, BUG-04, BUG-05, BUG-06, BUG-09, UX-02
5. **Content & spine** — BUG-11, UX-01, FEAT-01, BUG-10
6. **Converters** — CONV-01..06, SEC-05, SEC-06, SEC-04, DOC-01
7. **Tests, CI, docs** — SWEEP-02, TEST-02, TEST-03, CI-02, CI-03, DOC-02
8. **Features** — FEAT-02, FEAT-03, UX-04

## Items

### Security (untrusted input)

- [ ] **SEC-01** Confine `container.xml` `full-path` and NCX/manifest hrefs to the extraction directory — `src/Parser.php:24-28,75-81,113-116`, `src/EpubFile.php:55-60`, `src/Metadata.php:41-46` — M · high
  The path from the untrusted book is concatenated onto the temp dir without normalisation, so `../` segments make `load()` parse XML outside the extraction dir and `Metadata::save()` overwrite it.
- [ ] **SEC-02** Reject `ContentManager` paths that resolve outside the content directory — `src/ContentManager.php:56-125` — S · med
  Callers typically pass manifest hrefs from the book, and `../` lets `add/update/delete/getContent` touch arbitrary files.
- [ ] **SEC-03** Cap total uncompressed size, entry count and compression ratio before extracting — `src/ZipHandler.php:21-38` — M · med
  `extractTo()` inflates every entry without limits, so a small zip bomb fills the temp disk (PHP already strips `../` from entry names).
- [ ] **SEC-04** Stop disabling TLS verification when downloading AFM fonts — `scripts/generate-core-fonts.php:83-86` — S · med
  `verify_peer => false` on a script that Composer runs on every install/update lets a network attacker substitute font data.
- [ ] **SEC-05** Accept Calibre `extra_args` as a list and escape each argument — `src/Converters/CalibreAdapter.php:44-50` — S · med
  `extra_args` is appended raw to the shell command, so any user-influenced option becomes shell injection.
- [ ] **SEC-06** Lock down HTML renderers for untrusted book content (Dompdf `chroot` to the book dir, remote/PHP off; TCPDF image paths confined) — `src/Converters/DompdfAdapter.php:47-50`, `src/Converters/TCPDFAdapter.php:127-130` — M · med
  Book XHTML can reference local files or URLs that the renderer would read into the PDF.
- [ ] **SEC-07** Create the temp dir with mode `0700` and a `random_bytes` name — `src/EpubFile.php:47-50` — S · low
  `uniqid()` plus the default `0777` mode leaves extracted content readable by other local users.
- [ ] **SEC-08** Do not follow symlinks in `deleteDirectory()` — `src/Util/FileSystemHelper.php:36-55` — S · low
  `is_dir()` is true for a symlink to a directory, so cleanup would recurse into and delete the link target.
- [ ] **SEC-09** Parse XML with explicit `LIBXML_NONET`, reject DOCTYPEs in package files, and report libxml errors instead of `@` — `src/XmlParser.php:18-30` — S · low
  Relies on libxml defaults for XXE safety and the `@` hides why a book failed to load.

### Bugs

- [ ] **BUG-01** Write `mimetype` as the first, stored ZIP entry on save — `src/ZipHandler.php:49-85` — S · high
  Probe: re-saving `valid.epub` put `mimetype` at index 15, so every saved book fails EPUBCheck and strict readers reject it.
- [ ] **BUG-02** Use `/` in ZIP entry names on Windows — `src/ZipHandler.php:72-78` — S · high
  Probe on Windows produced entries like `EPUB\css\base.css`, which ZIP/OCF readers cannot resolve.
- [ ] **BUG-03** Keep the `dc:identifier` referenced by `package@unique-identifier` in `setIdentifiers()` — `src/Traits/InteractsWithIdentifier.php:38-54` — S · high
  All identifiers (and their `id`s) are deleted, leaving `unique-identifier` dangling and the OPF invalid.
- [ ] **BUG-04** Persist metadata edits from `EpubFile::save()` — `src/EpubFile.php:63-75` — S · med
  Edits are silently lost unless the caller also remembers `getMetadata()->save()`.
- [ ] **BUG-05** Preserve `opf:role`/`opf:file-as` and EPUB 3 `refines` metas when setting authors/identifiers — `src/Traits/InteractsWithAuthors.php:37-52` — M · med
  Removing creators leaves `<meta refines="#id">` pointing at nothing and drops role information.
- [ ] **BUG-06** Update EPUB 3 `dcterms:modified` when metadata is saved — `src/Metadata.php:41-48` — S · med
  EPUB 3 requires it to reflect the last modification; it stays stale after edits.
- [ ] **BUG-07** Treat an empty `rootfile` / manifest XPath result as an error — `src/Parser.php:69-75,100-104` — S · med
  `xpath()` returns `[]` not `false`, so `$rootfiles[0]` raises an undefined-key warning and a missing manifest is accepted.
- [ ] **BUG-08** Handle NCX files without a default namespace — `src/Parser.php:124-132` — S · low
  `$namespaces['']` is an undefined key there, producing a warning instead of a clear error.
- [ ] **BUG-09** Support prefixed OPF packages (`opf:package`) and books without any `dc:` element — `src/Metadata.php:25-34`, `src/Spine.php:19-25`, `src/Traits/*.php` — M · med
  `$opfXml->metadata` / `->spine` only work with an unprefixed default namespace, and the constructor throws when no `dc` prefix is declared.
- [ ] **BUG-10** Clean up the previous temp dir when `load()` is called twice — `src/EpubFile.php:45-61` — S · low
  Each extra `load()` leaks a full extracted copy of the book.
- [ ] **BUG-11** Keep manifest and spine in sync on `addContent`/`deleteContent` (and create parent dirs) — `src/ContentManager.php:56-104` — M · med
  Added files are invisible to readers and deleted files leave dangling manifest/spine entries.

### Converters

- [ ] **CONV-01** Render spine documents in order instead of a hard-coded `content.xhtml` — `src/Converters/DompdfAdapter.php:83-97`, `src/Converters/TCPDFAdapter.php:135-149` — M · high
  Real books have no root `content.xhtml`, so both PDF adapters fail on every real EPUB.
- [ ] **CONV-02** Make `Converter` work with `CalibreAdapter` (it needs the `.epub`, `Converter` passes the extracted dir) — `src/Converter.php:37-44`, `src/Converters/CalibreAdapter.php:31` — S · med
  The interface says "directory", Calibre expects a file, so `Converter` + Calibre (the docs example) cannot work.
- [ ] **CONV-03** Resolve TCPDF fonts when php-epub is installed as a dependency — `src/Converters/TCPDFAdapter.php:48-50`, `composer.json` scripts — M · med
  `K_PATH_FONTS` points at php-epub's own `vendor/`, and `post-install-cmd` only runs for the root package.
- [ ] **CONV-04** Fill TCPDF document info from EPUB metadata instead of `'Author Name'` — `src/Converters/TCPDFAdapter.php:55-60` — S · low
  Every PDF claims the same placeholder author and title.
- [ ] **CONV-05** Check `file_put_contents` results and capture Calibre stderr — `src/Converters/DompdfAdapter.php:76`, `src/Converters/TCPDFAdapter.php:131`, `src/Converters/CalibreAdapter.php:44-50` — S · low
  Write failures are silent and Calibre errors arrive with an empty message.
- [ ] **CONV-06** Align defaults and honour `font_size` in Dompdf — `src/Converters/TCPDFAdapter.php:90-115`, `src/Converters/DompdfAdapter.php:24-30` — S · low
  Fallbacks (font size 27, bottom margin 27) contradict the declared defaults (12, 25) and Dompdf ignores `font_size`.

### API / UX

- [ ] **UX-01** Return paths relative to the book root from `getContentList()` — `src/ContentManager.php:30-44` — S · med
  It returns absolute temp paths while every other method takes relative ones, so its output cannot be fed back in.
- [ ] **UX-02** Add list-valued `getSubjects()`/`setSubjects()` (and titles) — `src/Traits/InteractsWithSubject.php:13-40` — S · low
  Books usually have several subjects; only the first is reachable.
- [ ] **UX-03** Introduce specific exception subclasses (invalid book, ZIP, conversion) — `src/Exception.php` — M · low
  Callers cannot tell a corrupt book from an I/O failure.
- [ ] **UX-04** Add `EpubFile::open()` and a convenience `convert()` — `src/EpubFile.php:21-61` — S · low
  Removes the "must be loaded before…" class of misuse and the manual temp-dir plumbing for conversion.

### Features

- [ ] **FEAT-01** Richer spine: `linear` flag, idref→href resolution, add/remove/reorder — `src/Spine.php` — M · med
  The spine is a read-only list of idrefs that callers must resolve by hand.
- [ ] **FEAT-02** Cover image get/set (EPUB 2 `meta name="cover"` and EPUB 3 `cover-image`) — new — M · med
  The most requested metadata operation after title/author is missing.
- [ ] **FEAT-03** Generic `<meta>` get/set (e.g. `calibre:series`, EPUB 3 `property`) — `src/Metadata.php` — M · low
  Anything outside the eight Dublin Core traits is unreachable.

### Documentation

- [ ] **DOC-01** Fix the `Converter` example that wires `CalibreAdapter` with an extracted directory — `docs/converter.md:15-31` — S · med
  The documented example cannot work (see CONV-02).
- [ ] **DOC-02** Add a "Handling untrusted EPUBs" section and document the `save()` contract — `docs/advanced-usage.md`, `docs/epub-file.md` — S · low
  Users need to know which limits exist and that metadata must be saved before the book.

### Tests

- [ ] **TEST-01** Add `tests/Support/EpubBuilder` and an OCF round-trip test (save → reopen → `mimetype` first/stored, `/` separators) — `tests/` — M · high
  Nothing asserts that saved books are structurally valid, which is how BUG-01/BUG-02 went unnoticed.
- [ ] **TEST-02** Cover `PhpEpub\Converter` (no test references it) — `tests/` — S · low
  Format dispatch and the unsupported-format error are untested.
- [ ] **TEST-03** Fix `phpunit.xml`: suite named "PhpIso Testing Suite", excludes non-existent `src/Cli/`, schema 8.3 — `phpunit.xml:14-27` — S · low
  Copy-paste leftovers from php-iso.

### CI

- [ ] **CI-01** Add `windows-latest` to the test matrix — `.github/workflows/tests.yml:17-25` — S · med
  BUG-02 only shows up on Windows.
- [ ] **CI-02** Add `composer audit` and gitleaks; quote `$GITHUB_OUTPUT` (actionlint SC2086) — `.github/workflows/tests.yml:41`, `.github/workflows/deploy-docs.yml:30` — S · low
  Dependency advisories and leaked secrets are not gated.
- [ ] **CI-03** Run the same gate commands as `composer quality` (PHPStan `memory_limit`, PHPCS paths) — `.github/workflows/tests.yml:64-71` — S · low
  Local and CI gates differ.

### Sweeps

- [ ] **SWEEP-01** Share one scoped metadata lookup helper across the eight `Interacts*` traits (`/opf:package/opf:metadata/dc:*` instead of `//dc:*`) — `src/Traits/*.php` — M · med
  The same xpath/empty-check block is copied eight times and matches `dc:` elements anywhere in the document.
- [ ] **SWEEP-02** Raise method coverage (52%) on error paths: `ZipHandler` (0/2), `Parser`, trait setters' "add new node" branch, `ContentManager` — `tests/` — M · med
  Most failure branches are never executed.
