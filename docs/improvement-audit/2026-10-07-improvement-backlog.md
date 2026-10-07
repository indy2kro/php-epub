# php-epub improvement backlog — 2026-10-07 (round 4)

Discovery-only audit of `src/`, `scripts/`, `docs/`, `tests/` and `.github/workflows/`
at `88a2210` (main), after the round-3 backlog was closed (#131-#138).
No source changes were made while compiling this list.

## Gate results (fresh dependencies, `composer update`)

| Gate | Result |
|---|---|
| PHPUnit 12.5 (`XDEBUG_MODE=coverage`) | 526 tests, 2 skipped; lines 99.6% (1939/1946) |
| PHPCS `src/ tests/ docs/` | clean |
| PHPStan 2.3 level max (512M) | clean |
| Rector 2.7 `--dry-run` | clean |
| `composer audit` / `validate --strict` / `outdated --direct` | clean / valid / up to date |
| gitleaks | clean |
| actionlint | clean |

The 7 uncovered lines are defensive branches in `ZipHandler` and `FileSystemHelper`, so the gates produced
no mechanical sweep items. The two sweep items below are behaviour fixes that span several classes.

## Summary

| Area | Items | High | Med | Low |
|---|---:|---:|---:|---:|
| SEC — untrusted input | 0 | 0 | 0 | 0 |
| BUG — wrong behaviour | 5 | 0 | 2 | 3 |
| CONV — converters | 4 | 0 | 1 | 3 |
| FEAT — new capability | 12 | 0 | 6 | 6 |
| UX — public API | 4 | 0 | 0 | 4 |
| TEST — tests | 2 | 0 | 0 | 2 |
| CI — workflows & packaging | 1 | 0 | 0 | 1 |
| SWEEP — cross-cutting | 2 | 1 | 1 | 0 |
| **Total** | **30** | **1** | **10** | **19** |

Effort: S = one file, under 30 min · M = one class plus tests · L = cross-cutting.

No security vulnerabilities were found. ZIP extraction, XML parsing and PDF resource confinement
were re-checked against the round-3 hardening.

## Execution instructions

- One branch and one PR per theme (see *Themes* below); stack PRs that touch the same files.
- Write the failing test first. Build hostile EPUBs inside the test (`tests/Support/EpubBuilder`)
  rather than committing binary fixtures.
- Before every push run the gates against fresh dependencies, as CI does
  (`composer update`, `composer quality`, then `git checkout composer.lock`), and check each exit code.
- Tick the box here in the same PR that fixes an item. Delete `docs/improvement-audit/` once every
  item is done or explicitly declined (list declined items in that PR's description).

### Themes

1. **Content & table-of-contents integrity**: SWEEP-01, BUG-01, BUG-04, FEAT-04, FEAT-07
2. **Archives & I/O**: BUG-02, BUG-03, UX-04, FEAT-05
3. **Converters**: SWEEP-02, CONV-01, CONV-03, CONV-04, UX-02
4. **Metadata & validation**: FEAT-02, FEAT-03, UX-01, UX-03, BUG-05
5. **Encryption & fonts**: FEAT-01, FEAT-09, CONV-02
6. **EPUB 3 features**: FEAT-06, FEAT-10, FEAT-11, FEAT-12, FEAT-08
7. **Tests & packaging**: TEST-01, TEST-02, CI-01

## Items

### Sweeps

- [x] **SWEEP-01** Reject malformed XHTML in `addChapter()`, `addContent()` and `updateContent()`, or convert HTML fragments (`&nbsp;`, `<br>`) to XHTML — `src/EpubFile.php:170-191`, `src/BookTemplate.php:59-66`, `src/ContentManager.php:91-134`, `src/Util/ContentDocumentProperties.php:40-50` — M · high
  Probe: `addChapter('C', '<p>a&nbsp;b</p>')`, a `<br>` or an unclosed tag is accepted, and the chapter it writes cannot be parsed by any XHTML reader. Only a later `validate()` reports `CONTENT_NOT_WELL_FORMED`.
- [x] **SWEEP-02** Throw `ConversionException` for renderer failures, and say how to fix missing TCPDF core fonts (`scripts/generate-core-fonts.php`) — `src/Converters/TCPDFAdapter.php:57-66,88-115`, `src/Converters/DompdfAdapter.php:48-70`, `scripts/generate-core-fonts.php:1-30` — S · med
  Probe: without the generated fonts, which is the case for every project that installs php-epub as a dependency and skips the manual step, `convert()` throws `Com\Tecnick\Pdf\Font\Exception: unable to read file: helvetica.json`. Neither adapter catches Dompdf or tc-lib exceptions, although both document `ConversionException`.

### Bugs

- [x] **BUG-01** Keep the NCX and nav valid when the table of contents becomes empty (navMap needs a navPoint, the toc `<ol>` an `<li>`), report empty ones in `validate()`, and update the NCX `dtb:depth` — `src/TableOfContents.php:93-130,365-410`, `src/ContentManager.php:279-297`, `src/Validator.php:265-330` — S · med
  Probes: `setEntries([])` (or deleting every chapter the TOC links to) writes `<navMap></navMap>` in EPUB 2 and `<ol/>` in EPUB 3. EPUBCheck rejects both (RSC-005), but `validate()` reports nothing.
- [x] **BUG-02** Detect ZIP entry names that collide after Unicode case folding or normalization, not only ASCII case — `src/ZipHandler.php:114-121` — S · med
  Probe: on Windows, an archive with `É.txt` and `é.txt` extracts into one file without an error, and the second entry silently replaces the first, because the duplicate check uses `strtolower()`. macOS also merges NFC and NFD spellings.
- [x] **BUG-03** Report entry names that are invalid on Windows (trailing dot or space, reserved device names such as `CON`, `:`) with a clear error — `src/ZipHandler.php:131-135`, `src/Util/PathResolver.php:20-55` — S · low
  Probe: `a.txt.` or `text./a.txt` fails with a misleading "Permission denied" from `fopen()`, and names such as `CON` or `a.xhtml:x` (an alternate data stream) are not rejected up front.
- [x] **BUG-04** Allow case-only renames in `moveContent()` on case-insensitive filesystems — `src/ContentManager.php:193-195` — S · low
  Probe on Windows: `moveContent('EPUB/text/ch.xhtml', 'EPUB/text/Ch.xhtml')` throws "already exists" because `file_exists()` finds the source file itself.
- [ ] **BUG-05** Update `dcterms:modified` only on the book-level meta, never on a refinement with the same property — `src/Metadata.php:562-576` — S · low
  `updateModifiedDate()` takes the first meta with that property, including one with `refines="#…"`, so the book-level date stays stale and the refinement is overwritten.

### Converters

- [x] **CONV-01** Render non-Latin books: default to a Unicode font (both renderers ship DejaVu) or choose one from `dc:language`, and switch TCPDF to RTL for right-to-left books — `src/Converters/DompdfAdapter.php:17-22,85-95`, `src/Converters/TCPDFAdapter.php:14-25,88-115` — M · med
  The defaults (Dompdf `Arial`, TCPDF `helvetica`) map to the PDF core fonts, which cover Latin-1 only. Cyrillic, Greek, CJK and Arabic books without embedded fonts lose their glyphs, and nothing in either adapter sets right-to-left layout.
- [ ] **CONV-02** De-obfuscate IDPF/Adobe obfuscated fonts before the PDF renderers load them — `src/Converters/EpubDocumentLoader.php:393-456,465-499`, `src/FontObfuscation.php` — S · low
  `@font-face` `url()`s point the renderers at the obfuscated files, because nothing in `src/Converters` reads `encryption.xml`, so the book's embedded fonts cannot be used in the PDF.
- [x] **CONV-03** Decode content documents in their declared encoding (EPUB allows UTF-16) instead of always forcing UTF-8 for the HTML parser — `src/Converters/EpubDocumentLoader.php:207-216`, `src/EpubFile.php:445-455` — S · low
  The `<?xml encoding="UTF-8">` prefix makes libxml read UTF-16 chapters as UTF-8. Their text is garbled in the PDF, and `getCoverImage()` cannot find the image in a UTF-16 cover page.
- [x] **CONV-04** Pass paths to `ebook-convert` so that a leading `-` is never read as an option, and document that Calibre receives the book unsanitised (keep Calibre up to date) — `src/Converters/CalibreAdapter.php:96-112`, `docs/converters/calibre-adapter.md` — S · low
  Unlike the TCPDF and Dompdf path, `CalibreAdapter` hands the book to Calibre unchanged, so keeping resources inside the book depends on the Calibre version. Calibre also parses an output name such as `-x.pdf` as an option.

### Features

- [ ] **FEAT-01** Detect DRM-protected books (`encryption.xml` algorithms other than font obfuscation, `rights.xml`, an LCP license): add `isEncrypted()`, report them in `validate()`, refuse PDF conversion — `src/FontObfuscation.php:86-110`, `src/Validator.php`, `src/EpubFile.php:278-287` — M · med
  An Adobe ADEPT or Readium LCP book loads without any warning. `getContent()` then returns ciphertext, `validate()` reports every chapter as not well-formed, and the converters produce garbage PDFs.
- [ ] **FEAT-02** Extend `validate()` with frequent EPUBCheck package errors: declared media type vs. file content, missing EPUB 3 manifest properties (`svg`, `mathml`, `scripted`, `remote-resources`) in loaded books, `cover-image` on a non-image — `src/Validator.php:150-300`, `src/Util/ContentDocumentProperties.php` — M · med
  Round 3 sets these properties for content the library writes. Loaded books with wrong media types or missing properties still pass `validate()` and fail EPUBCheck (OPF-014, OPF-029).
- [ ] **FEAT-03** Accessibility metadata API (`schema:accessMode`, `accessibilityFeature`, `accessibilityHazard`, `accessibilitySummary`, `dcterms:conformsTo`) with `validate()` hints — `src/Metadata.php:166-222`, `src/Validator.php` — M · med
  Since June 2025 the European Accessibility Act requires accessibility metadata for e-books sold in the EU. Today callers have to know the property names and write them through the generic `setPropertyValues()`.
- [x] **FEAT-04** Rewrite references in content documents when `moveContent()` moves a file (links, images, stylesheets, and the relative links of the moved document itself) — `src/ContentManager.php:168-225` — M · med
  `moveContent()` keeps the manifest, spine, TOC and `encryption.xml` consistent, but every `<a>`, `<img>` and `<link>` that pointed at the file breaks, so reorganising a book still needs a hand-written rewrite pass.
- [x] **FEAT-05** Open a book from a string or stream, and save it to one (uploads, HTTP downloads) — `src/EpubFile.php:57-63,251-271` — M · med
  Web apps receive uploads and send downloads, but the API only reads and writes file paths, so callers write their own temp-file plumbing.
- [ ] **FEAT-06** Upgrade an EPUB 2 book to EPUB 3: package version, a nav document generated from the NCX, `dcterms:modified`, EPUB 3 refinements for `opf:role`/`opf:file-as`, the `cover-image` property — `src/Metadata.php`, `src/TableOfContents.php`, `src/Manifest.php` — L · med
  Stores and validators increasingly require EPUB 3. The library already has every piece of the migration, but callers must assemble them by hand.
- [x] **FEAT-07** Generate the table of contents from the headings (`h1`–`h3`) of the spine documents — `src/TableOfContents.php:93-130`, `src/EpubFile.php:170-191` — M · low
  Books assembled from existing XHTML need a TOC built from their headings, and callers have to write it themselves with DOM parsing and `setEntries()`.
- [ ] **FEAT-08** Plain-text extraction per spine document (search indexing, word counts, previews) — `src/ContentManager.php`, `src/Converters/EpubDocumentLoader.php:200-239` — S · low
  Getting a book's text is one of the most common reasons to open an EPUB, and today it takes a hand-written spine walk plus HTML parsing.
- [ ] **FEAT-09** Add obfuscated fonts and read fonts de-obfuscated (expose `FontObfuscation` through `ContentManager`) — `src/FontObfuscation.php:31-80`, `src/ContentManager.php:91-110` — S · low
  The library re-keys obfuscated fonts but cannot add one or return a usable font file, so publishers who obfuscate embedded fonts must implement the IDPF algorithm themselves.
- [ ] **FEAT-10** Landmarks API (EPUB 3 nav `landmarks` and the EPUB 2 `guide`) and page-list access — `src/TableOfContents.php:60-90`, `src/Manifest.php:246-275` — M · low
  Landmarks (cover, start of text, TOC) drive a reader's "go to beginning", and today they can only be changed by editing the nav XHTML and the guide by hand.
- [ ] **FEAT-11** Fixed-layout and rendition API: `rendition:layout`, `orientation`, `spread`, and the `page-spread-*` properties of spine itemrefs — `src/Spine.php:73-170`, `src/Metadata.php` — M · low
  Comics and children's books use fixed layout and need these properties, but no accessor reads or writes them.
- [ ] **FEAT-12** Media overlays: read and set an item's `media-overlay` SMIL, plus the `media:duration` and `media:active-class` metadata — `src/Manifest.php:355-370`, `src/Metadata.php:166-222` — M · low
  Read-aloud books need these. The library keeps existing `media-overlay` references consistent but has no API to create them.

### Public API

- [ ] **UX-01** Check language tags (BCP 47) and dates (W3CDTF) when they are set, and report invalid ones in `validate()` — `src/Traits/InteractsWithLanguage.php:20-45`, `src/Traits/InteractsWithDate.php:25-30` — S · low
  `setLanguage('English')` and `setDate('July 2020')` are accepted. EPUBCheck then reports OPF-092 (invalid language tag, an error) and OPF-053 (date syntax).
- [x] **UX-02** Reject invalid converter styles (wrong types, unknown keys, unknown paper sizes) instead of silently using the defaults — `src/Converters/TCPDFAdapter.php:35-44,189-208`, `src/Converters/DompdfAdapter.php:25-35,149-162` — S · low
  `'margin_left' => 12.5`, a typo such as `fontsize`, or a paper size of `A44` is ignored without a word, so callers cannot tell why the PDF looks wrong.
- [ ] **UX-03** Add `setCreators()`/`setContributors()` that take `Contributor` objects (name, role, file-as), the inverse of `getCreators()` — `src/Traits/InteractsWithAuthors.php:30-60`, `src/Traits/InteractsWithContributors.php:16-42` — S · low
  `getCreators()` returns roles and sort keys, but changing one author's file-as or role means removing every creator and adding them all back with `addCreator()`.
- [x] **UX-04** Check the result of `ZipArchive::addFile()` in `compress()` and include the libzip status in the error — `src/ZipHandler.php:213-235` — S · low
  `addFile()` failures are ignored, and `close()` then fails with a bare "Failed to finalize ZIP file" that names neither the file (for example, one locked on Windows) nor the reason.

### Tests

- [ ] **TEST-01** Run the PHP examples in `README.md` and `docs/` in the test suite (lint every block, execute the self-contained ones against a fixture) — `README.md`, `docs/*.md`, `tests/` — M · low
  PHPCS on `docs/` does not look inside Markdown and no test reads `docs/`, so examples drift silently whenever the API changes. Round 3's DOC-01 and DOC-02 fixed drift of this kind.
- [ ] **TEST-02** Fuzz the untrusted-input parsers (`ZipHandler::extract()`, `XmlParser`, `Parser`, `EpubDocumentLoader`) with mutated fixtures in a scheduled job — `tests/Support/EpubBuilder.php`, `.github/workflows/tests.yml` — M · low
  Hostile-input tests are hand-written cases. A cheap mutation fuzzer (truncated entries, flipped bytes, random names) would find crashes and non-library exceptions that example-based tests miss.

### CI

- [ ] **CI-01** Mark dev-only files `export-ignore`: `docs/`, `mkdocs.yml`, `AGENTS.md`, `phpcs.xml`, `phpstan.neon`, `phpunit.xml`, `rector.php`, `composer.lock`, `.gitignore` — `.gitattributes:10-20` — S · low
  `git archive HEAD` shows that all of them ship in the Composer dist package, which every dependent downloads.
