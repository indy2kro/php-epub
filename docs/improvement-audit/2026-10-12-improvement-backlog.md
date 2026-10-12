# php-epub improvement backlog — 2026-10-12 (round 5)

Discovery-only audit of `src/`, `scripts/`, `docs/`, `tests/` and `.github/workflows/`
at `b5ce66f` (main), after the round-4 backlog was closed (#139-#146) and the web-tools work landed (#147-#153).
No source changes were made while compiling this list.

## Gate results (fresh dependencies, `composer update`)

| Gate | Result |
|---|---|
| PHPUnit 12.5.39 | 1577 tests, 15 skipped (all environment: Windows file names, symlinks, GD, `ZipArchive::openString()`) |
| PHPUnit with Xdebug coverage | **fatal at the default 128M memory limit** (TEST-02); with 1G: lines 99.94% (6744/6748) |
| PHPCS `src/ tests/ docs/` | clean |
| PHPStan 2.3.1 level max (512M) | clean |
| Rector 2.7.0 `--dry-run` | clean |
| `composer audit` / `validate --strict` / `outdated --direct` | clean / valid / up to date |
| gitleaks | clean |
| actionlint | clean |

The 4 uncovered lines are the symlink branches in `FileSystemHelper` and `ZipHandler` (as in round 4).
The CI matrix precedence bug (`schedule || dispatch && …`) is fixed on `ci/fix-schedule-matrix` (bdef3f3) and is not repeated here.

## Summary

| Area | Items | High | Med | Low |
|---|---:|---:|---:|---:|
| SEC — untrusted input | 1 | 0 | 1 | 0 |
| BUG — wrong behaviour | 7 | 0 | 1 | 6 |
| CONV — converters | 2 | 0 | 1 | 1 |
| FEAT — new capability | 5 | 0 | 3 | 2 |
| UX — public API | 4 | 0 | 0 | 4 |
| DOC — documentation | 1 | 0 | 0 | 1 |
| TEST — tests | 2 | 0 | 0 | 2 |
| SWEEP — cross-cutting | 4 | 0 | 3 | 1 |
| **Total** | **26** | **0** | **9** | **17** |

Effort: S = one file, under 30 min · M = one class plus tests · L = cross-cutting.

The target was 30. Only 26 items held up after checking, and the list was not padded. No security vulnerabilities were found.
ZIP extraction, `EpubReader` streaming limits, `XmlParser`, `HtmlSanitizer`/`CssSanitizer` and the HTML export's
`<style>` escaping and CSP were re-checked; SEC-01 is hardening. The new modules (`Cleanup`, `Merge`, `Split`,
`Repair`, `Kindle`, `Build`, `EpubReader`) were reviewed as two extra batches.

## Execution instructions

- One branch and one PR per theme (see *Themes* below); stack PRs that touch the same files.
- Write the failing test first. Build hostile EPUBs inside the test (`tests/Support/EpubBuilder`)
  rather than committing binary fixtures.
- Before every push run the gates against fresh dependencies, as CI does
  (`composer update`, `composer quality`, then `git checkout composer.lock`), and check each exit code.
- Tick the box here in the same PR that fixes an item. Delete `docs/improvement-audit/` once every
  item is done or explicitly declined (list declined items in that PR's description).

### Themes

1. **Limits & untrusted input**: SEC-01, SWEEP-03, BUG-02, BUG-06
2. **Validation & repair**: SWEEP-02, BUG-04, FEAT-03, FEAT-04
3. **Content integrity**: BUG-01, BUG-03, BUG-05, UX-02
4. **Merge & split**: BUG-07, UX-03, UX-04
5. **Converters**: CONV-01, CONV-02
6. **Reader, CLI & I/O**: FEAT-01, FEAT-02, FEAT-05, UX-01
7. **Packaging, docs & tests**: SWEEP-01, SWEEP-04, DOC-01, TEST-01, TEST-02

## Items

### Sweeps

- [ ] **SWEEP-01** Require `ext-mbstring` (or guard every `mb_*` call) — `composer.json:30-33`, `src/BookSummary.php:392`, `src/Kindle/KindleChecker.php:289`, `src/Converters/TextAdapter.php:75,112`, `src/Merge/Merger.php:298`, `src/Util/WordCount.php:27`, `README.md:36` — S · med
  `ext-mbstring` is only in `require-dev`. `ZipHandler` and `TextEncoding` guard it, but on a build without mbstring (a separate package on Alpine and most distros) `toArray()`, the Kindle check, text export and merges die with `Error: Call to undefined function`. That breaks the "only `PhpEpub\Exception` escapes" rule, and no CI job runs without mbstring.
- [ ] **SWEEP-02** Close the most frequent EPUBCheck gaps in `validate()`: broken link fragments (RSC-012), `dcterms:modified` syntax, NCX `dtb:uid` mismatch (NCX-001), undeclared property prefixes (OPF-028) — `src/Validator.php:150-330` — M · med
  `validate()` checks referenced files but strips their `#fragment` (line 319), accepts any `dcterms:modified` text, and only fixes `dtb:uid` on save. Loaded books pass `validate()` and still fail EPUBCheck.
- [ ] **SWEEP-03** Carry the configured limits into every collaborator: `EpubFile::convert()` adapters (their default `EpubDocumentLoader` has no XML or HTML cap) and `Merger`'s `ReferenceRewriter` (built with an uncapped `XmlParser` even when one is passed) — `src/EpubFile.php:487-500`, `src/Converters/HtmlAdapter.php:53-58`, `src/Converters/TextAdapter.php:26-31`, `src/Converters/MarkdownAdapter.php:31-37`, `src/Converters/EpubDocumentLoader.php:150-158`, `src/Merge/Merger.php:52-57,425-435`, `src/Limits.php:21-24` — M · med
  A book opened with `Limits::web()` and converted with `new HtmlAdapter()` is parsed without size caps. `Limits` documents this, but a service that sets its limits once still gets unbounded conversions and merges.
- [ ] **SWEEP-04** Document or mark `@internal` the undocumented public API: Manifest `addMany`/`getFallback`/`setFallback`/`getPackageReferences`/`renameDuplicateIds`, Spine `addMany`/`removeAt`/`getToc`/`setToc`, TableOfContents `createNavigation`/`syncNcx`/`writeEntries`, `ContentManager::getMarkup`, and the helper classes `BookPackage`, `MarkdownParser`, `ImageRecompressor`, `MarkupSanitizer`, `BookImages`, `Encryption`, `FontObfuscation` — `docs/manifest.md`, `docs/spine.md`, `docs/table-of-contents.md`, `docs/content-manager.md`, `src/Build/`, `src/Cleanup/`, `src/Converters/BookImages.php` — M · low
  Checking every public method against `docs/` and `README.md` finds 13 core methods and about 35 helper methods that are mentioned nowhere and not `@internal`, so it is unclear which methods semver covers.

### Security hardening

- [ ] **SEC-01** Add a compressed-size cap (`Limits::$maxArchiveBytes`, set in `web()`) enforced while `openStream()`/`openString()` buffer the archive, in `EpubFile` and `EpubReader` — `src/EpubFile.php:118-133`, `src/EpubReader.php:140-160`, `src/Limits.php:17-35` — S · med
  `openStream()` copies the whole stream to a scratch file with an unbounded `stream_copy_to_stream()` before any limit applies, so `Limits::web()` cannot stop a multi-gigabyte request body from filling the temp disk. Callers usually cap uploads, so this is hardening, not a vulnerability.

### Bugs

- [ ] **BUG-01** Remove a file's `META-INF/encryption.xml` entry in `deleteContent()`, and report entries that name missing files in `validate()` — `src/ContentManager.php:164-187`, `src/FontObfuscation.php:130-160`, `src/Validator.php` — S · med
  Probe: `addFont()` then `deleteContent()` saves an `encryption.xml` that still lists the font, and `validate()` is silent. EPUBCheck reports the missing resource. A plain file added later at that path is "de-obfuscated" into garbage by reading systems.
- [ ] **BUG-02** Treat an unreadable or oversized `encryption.xml` as possibly DRM-protected instead of not protected — `src/Encryption.php:69-90,122-133`, `src/Merge/Merger.php:305-311` — S · low
  `safeEntries()` turns any `XmlException` (malformed, an entity declaration, larger than `maxXmlBytes`) into "no entries". `isDrmProtected()` fails open, `convert()` then runs on ciphertext, and `Merger` copies obfuscated fonts without their entries.
- [ ] **BUG-03** Keep the package and nav `xml:lang`, and the nav `<title>`, in step with `setLanguage()`/`setTitle()` — `src/Traits/InteractsWithLanguage.php:20-72`, `src/Traits/InteractsWithTitle.php`, `src/TableOfContents.php:482-535` — S · low
  Probe: `create($p, 'T', 'en')`, then `setLanguage('fr')` and `setTitle('Nouveau')`, saves `<package xml:lang="en">` and a nav with `xml:lang="en"` and `<title>T</title>`. The NCX `docTitle` is synced, but the nav is not.
- [ ] **BUG-04** Reject or auto-declare undeclared prefixes in `setProperty()`/`setPropertyValues()` — `src/Metadata.php:166-230`, `src/Util/PackagePrefixes.php:11-33` — S · low
  Probe: `setPropertyValues('foo:bar', ['x'])` is accepted, but EPUBCheck rejects the package (OPF-028). Only `rendition:` is ever declared.
- [ ] **BUG-05** Make `moveContent()` rewrite media overlay (SMIL) `text`/`audio` `src` references, plus MathML `altimg` and `longdesc` — `src/ContentManager.php:541-566`, `src/Util/ReferenceRewriter.php:88-105`, `src/Cleanup/ReferenceGraph.php:36-45` — S · low
  `referenceRewrites()` reads only XHTML, SVG and CSS files, so moving a chapter of a read-aloud book leaves `<text src="chapter.xhtml#id">` dangling and the narration stops syncing. `ReferenceGraph` already follows these references.
- [ ] **BUG-06** Refuse or skip encrypted documents in `getText()` (`EpubFile`, `ContentManager`, `EpubReader`) — `src/EpubFile.php:647-653`, `src/ContentManager.php:380-390`, `src/EpubReader.php:288-300`, `src/Util/SpineText.php` — S · low
  For an Adobe ADEPT or LCP book, `getText()` extracts text from ciphertext and returns garbage for search indexes, while `convert()` throws and `BookSummary` skips `getEncryptedPaths()`.
- [ ] **BUG-07** Add a part to `Splitter`'s rollback list only after it is written, and report or refuse existing part files — `src/Split/Splitter.php:88-115` — S · low
  `$written[] = $path` runs before `ModifiedDate::save()`. A failed save of part N therefore unlinks an existing `prefix-N.epub` that the atomic write had left intact. A rerun with fewer parts leaves stale higher-numbered parts behind.

### Converters

- [ ] **CONV-01** Throw `ConversionException` from `CalibreAdapter` (failure, timeout, empty output, `ebook-convert` missing) and declare `@throws` on `ConverterInterface::convert()` — `src/Converters/CalibreAdapter.php:80-155`, `src/Converters/ConverterInterface.php:9-15`, `src/Util/FileSystemHelper.php:118-130` — S · med
  The other adapters throw `ConversionException`, but a failed or timed-out Calibre run throws plain `PhpEpub\Exception`. `catch (ConversionException)` misses it, and a timeout gets no `CODE_TIME_BUDGET_EXCEEDED`.
- [ ] **CONV-02** Use the book's nested TOC for PDF outlines (Dompdf writes none; TCPDF writes one flat level per spine document) and for the HTML export's contents list — `src/Converters/TCPDFAdapter.php:200-235`, `src/Converters/DompdfAdapter.php`, `src/Converters/HtmlAdapter.php:171-181`, `src/Converters/EpubDocumentLoader.php:340-350` — M · low
  The loader already reads the TOC, but sections within a chapter never reach the PDF bookmarks or the HTML contents.

### Features

- [ ] **FEAT-01** Read-only parity for `EpubReader`: `isDrmProtected()`, `getEncryptedPaths()`, `toArray()` without extracting — `src/EpubReader.php`, `src/Encryption.php:25-60`, `src/BookSummary.php:87-125` — M · med
  `EpubReader` is the recommended way to inspect untrusted uploads, but the JSON summary and the DRM check need the extracting `EpubFile`.
- [ ] **FEAT-02** Ship a CLI (`vendor/bin/php-epub`): `info` (toArray JSON), `validate` (profile, exit code), `repair`, `compress`, `convert`, `split`/`merge` — `composer.json`, `src/EpubFile.php`, `src/ValidationProfile.php` — M · med
  Everything is library-only. CI pipelines and publishers have to write PHP just to validate, shrink or convert a book.
- [ ] **FEAT-03** Content accessibility checks in `validate()`: images without `alt`, documents without `lang`, skipped heading levels, tables without headers, no page list for books with a print source — `src/Validator.php`, `src/Traits/InteractsWithAccessibility.php` — M · med
  `validate()` checks that accessibility metadata exists, but not the content it describes. The European Accessibility Act requires these WCAG basics for e-books sold in the EU.
- [ ] **FEAT-04** Repairer fixes for the new checks: drop dangling `encryption.xml` entries, remove or retarget links to missing fragments, declare used prefixes — `src/Repair/RepairFix.php:10-50`, `src/Repair/Repairer.php:59-120`, `src/Util/LinkRemover.php` — M · low
  `LinkRemover` and `FontObfuscation::setAlgorithm()` already provide most of the mechanics.
- [ ] **FEAT-05** Open and save an expanded (unzipped) EPUB directory — `src/EpubFile.php:86-92,244-255,392-413` — M · low
  Publishing workflows and EPUBCheck `--mode exp` work on folders, but `EpubFile` only opens archives.

### Public API

- [ ] **UX-01** Reject an empty target path in `save()`/`compress()` — `src/EpubFile.php:392-446`, `src/ZipHandler.php:286-300` — S · low
  `compress()` on a book from `openString()` resolves its target to `''`, which skips `save()`'s `hasFile` check. `ZipHandler` then creates `.<hex>.tmp` in the working directory and fails with a rename error that names no path.
- [ ] **UX-02** Make `Manifest::remove()` also drop the item's spine itemrefs, or refuse while the item is in the spine — `src/Manifest.php:240-256`, `docs/manifest.md:21` — S · low
  Probe: removing a chapter's item saves `<itemref idref>` pointing at nothing (SPINE_UNKNOWN_IDREF). This is documented, but it is the only reference `remove()` leaves dangling.
- [ ] **UX-03** Add warnings to `MergeReport` and the `Splitter` result for sources whose TOC, landmarks or font obfuscation could not be read — `src/Merge/MergeReport.php`, `src/Merge/Merger.php:757-784`, `src/Split/Splitter.php:355-376` — S · low
  Both degrade quietly, while `BuiltBook` and `CleanupReport` already carry warnings.
- [ ] **UX-04** Split from one extraction instead of re-extracting the whole book for every part — `src/Split/Splitter.php:85-100` — M · low
  `split()` holds `saveToString()` in memory and calls `openString()` per part, so splitting a 200 MB book into 50 parts extracts 10 GB.

### Documentation

- [ ] **DOC-01** Bring `AGENTS.md` and the audit skill up to date with `Cleanup`, `Merge`/`Split`, `Repair`, `Kindle`, `BookSummary` and `Build` — `AGENTS.md:20-75`, `.claude/skills/project-improvement-audit/SKILL.md` — S · low
  `AGENTS.md` never names these modules (about a quarter of `src/`), and the skill's six batches skip their directories.

### Tests

- [ ] **TEST-01** Add real-world fixtures (e.g. IDPF epub3-samples, CC BY-SA) for fixed layout, media overlays, RTL and CJK vertical text, obfuscated fonts and SVG, and run them through the EPUBCheck round-trip — `tests/fixtures/`, `tests/EpubCheckTest.php:260-290` — M · low
  All 7 fixtures are reflowable Latin-script books with no fonts, SVG, overlays or page-progression direction.
- [ ] **TEST-02** Make the coverage run fit the default memory limit (`memory_limit` in `phpunit.xml`, or a smaller image) — `tests/Cleanup/CleanupCoverageTest.php:258-272`, `phpunit.xml` — S · low
  With Xdebug coverage at 128M the suite dies in `testHugeAlphaPngsAreNotScannedForTransparency` (102 MB peak even without coverage). CI passes only because it uses pcov.
