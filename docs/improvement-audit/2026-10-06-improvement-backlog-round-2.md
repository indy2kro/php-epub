# php-epub improvement backlog — 2026-10-06 (round 2)

Discovery-only audit of `src/`, `scripts/`, `docs/`, `tests/` and `.github/workflows/`
at `7cd78fe` (main), after the first 2026-10-06 backlog was closed (#110-#120).
No source changes were made while compiling this list.

## Gate results (fresh dependencies, `composer update`)

| Gate | Result |
|---|---|
| PHPUnit 12.5 (`XDEBUG_MODE=coverage`) | 231 tests, 4 skipped (Calibre, symlinks, read-only files); lines 98.25%, methods 94.70% |
| PHPCS `src/ tests/ docs/` | clean |
| PHPStan level max (512M) | clean |
| Rector `--dry-run` | clean |
| `composer audit` / `validate --strict` / `outdated --direct` | clean / valid / up to date |
| gitleaks | clean |
| actionlint | clean |

## Summary

| Area | Items | High | Med | Low |
|---|---:|---:|---:|---:|
| SEC — untrusted input | 2 | 0 | 2 | 0 |
| BUG — wrong behaviour | 12 | 0 | 4 | 8 |
| CONV — converters | 4 | 0 | 2 | 2 |
| UX — public API | 4 | 0 | 0 | 4 |
| FEAT — new capability | 6 | 0 | 4 | 2 |
| DOC — documentation | 1 | 0 | 0 | 1 |
| TEST — tests | 1 | 0 | 1 | 0 |
| CI — workflows & packaging | 4 | 0 | 1 | 3 |
| SWEEP — cross-cutting | 2 | 1 | 0 | 1 |
| **Total** | **36** | **1** | **14** | **21** |

Effort: S = one file, under 30 min · M = one class plus tests · L = cross-cutting.

## Execution instructions

- One branch and one PR per theme (see *Themes* below); stack PRs that touch the same files.
- Write the failing test first. Build hostile EPUBs inside the test (`tests/Support/EpubBuilder`)
  rather than committing binary fixtures.
- Before every push run the gates against fresh dependencies, as CI does
  (`composer update`, `composer quality`, then `git checkout composer.lock`), and check each exit code.
- Tick the box here in the same PR that fixes an item. Delete `docs/improvement-audit/` once every
  item is done or explicitly declined (list declined items in that PR's description).
- SEC-01 and SEC-02 were flagged for information security review when found; fix them first.

### Themes

1. **Security hardening** — SEC-01, SEC-02
2. **Package integrity on save** — SWEEP-01, BUG-12, BUG-03, BUG-11, BUG-10, TEST-01
3. **Loading robustness** — BUG-01, BUG-05, BUG-04, BUG-02, BUG-06, BUG-09
4. **Cover & metadata** — BUG-07, BUG-08, FEAT-01, UX-01, UX-02, UX-03, UX-04
5. **Converters** — CONV-01, CONV-02, CONV-03, CONV-04, FEAT-06
6. **Tests, CI, docs** — SWEEP-02, CI-01, CI-02, CI-03, CI-04, DOC-01
7. **Features** — FEAT-02, FEAT-03, FEAT-04, FEAT-05

## Items

### Security (untrusted input)

- [x] **SEC-01** Detect entity declarations on the parsed document, not by searching raw bytes — `src/XmlParser.php:33-41` — S · med
  `preg_match('/<!ENTITY/')` runs on the file bytes, so a UTF-16 encoded container/OPF/NCX passes the guard and reaches libxml with its internal subset intact.
- [x] **SEC-02** Confine every resource reference the PDF renderers can follow (unquoted `src`, `srcset`, SVG `href`, `object`/`embed` `data`, CSS `url()`), ideally by parsing chapters with DOM — `src/Converters/EpubDocumentLoader.php:89-127` — M · med
  The rewrite only matches quoted values, but TCPDF 7 accepts unquoted attributes (`tc-lib-pdf/src/HTML.php:1811`), so book content can pull images from TCPDF's default read allowlist (system temp dir with other books, working dir, script dir) into the PDF.

### Bugs

- [ ] **BUG-01** Accept prefixed OPF packages and containers in `Parser` (look up the OPF/OCF namespace URIs instead of the default-namespace key) — `src/Parser.php:63-71,97-105` — S · med
  `getNamespaces(true)` has no `''` key for `<opf:package xmlns:opf=…>`, so `load()` throws "No OPF namespace" and the prefixed-package support in `Metadata`/`Spine` is unreachable (its test bypasses `Parser`).
- [ ] **BUG-02** Reset metadata/manifest/spine/content manager in `cleanup()` and when `load()` fails — `src/EpubFile.php:61-92` — S · med
  The getters keep returning objects bound to a deleted temp dir, so later edits and `save()` fail with confusing I/O errors.
- [x] **BUG-03** Clear dangling references when `deleteContent()`/`Manifest::remove()` drops an item (`<meta name="cover">`, `spine@toc`, `<guide>`) — `src/ContentManager.php:135-155`, `src/Manifest.php:136-142` — M · med
  Deleting the cover image or the NCX leaves references to an id/href that no longer exists, which readers and EPUBCheck reject.
- [ ] **BUG-04** Do not let one bad manifest href make the whole manifest unreadable — `src/Manifest.php:74-100,225-238` — S · med
  `toItem()` throws for an href like `../x`, so `getItems()`, `findByPath()`, `getCoverImage()` and `addContent()` all fail instead of skipping or flagging that item.
- [ ] **BUG-05** Pick the rootfile with media type `application/oebps-package+xml` instead of the first one — `src/Parser.php:73-81` — S · low
  Multi-rendition containers may list another rootfile first.
- [ ] **BUG-06** Make cloned `EpubFile` instances safe (copy the extraction, or forbid `__clone`) — `src/EpubFile.php:56-68` — S · low
  A clone shares the temp dir, so the first destructor deletes the book under the other instance.
- [ ] **BUG-07** Update the manifest media type when `setCoverImage()` reuses an existing path — `src/EpubFile.php:172` — S · low
  Replacing `images/cover.jpg` with PNG bytes keeps `media-type="image/jpeg"`.
- [ ] **BUG-08** Fall back to `<guide><reference type="cover">` and href-valued `<meta name="cover">` in `getCoverImage()` — `src/EpubFile.php:129-142` — S · low
  Many EPUB 2 books name the cover that way, and `getCoverImage()` returns null for them.
- [ ] **BUG-09** Report `deleteDirectory()` failures instead of emitting warnings from the destructor — `src/Util/FileSystemHelper.php:34-65` — S · low
  Recursive results are ignored, and a locked Windows file raises warnings from `__destruct` and leaks the temp dir silently.
- [x] **BUG-10** Keep `ContentManager` writes to the OPF consistent with the in-memory package (refuse them, or reload) — `src/ContentManager.php:89-126`, `src/EpubFile.php:193-204` — S · low
  `updateContent()` on the OPF is overwritten on the next `save()` when anything else changed, and kept otherwise.
- [x] **BUG-11** Make generated manifest ids unique across the whole OPF, not only among items — `src/Manifest.php:294-308` — S · low
  A new file can get an id already used by a `dc:*` or `meta` element, producing duplicate XML ids.
- [x] **BUG-12** Reject empty values for required fields (title, language, identifier) — `src/Metadata.php:188-199`, `src/Traits/InteractsWithTitle.php`, `src/Traits/InteractsWithLanguage.php` — S · low
  `setTitle('')` writes an empty `dc:title`, which EPUBCheck reports as an error.

### Converters

- [ ] **CONV-01** Run `ebook-convert` with a timeout (`proc_open`) and kill it on expiry — `src/Converters/CalibreAdapter.php:79-99`, `src/Util/FileSystemHelper.php:21-24` — S · med
  `exec()` waits forever, so a hostile or simply huge book hangs the worker.
- [ ] **CONV-02** Carry the book's own CSS into the HTML renderers (sanitised, `url()` confined) — `src/Converters/EpubDocumentLoader.php:81-100`, `src/Converters/DompdfAdapter.php:97-107` — M · med
  Only `<body>` is kept, so stylesheets are dropped and every PDF loses the book's layout. Do after SEC-02.
- [ ] **CONV-03** Find `ebook-convert` on `PATH` (and the usual Windows/macOS locations) when `calibre_path` is not given — `src/Converters/CalibreAdapter.php:31-34` — S · low
  The `/usr/bin/ebook-convert` default fails on Windows, macOS and `/usr/local` installs.
- [ ] **CONV-04** Accept the same options in both PDF adapters (`paper_size`/`orientation` in TCPDF, margins in Dompdf) — `src/Converters/TCPDFAdapter.php:12-21`, `src/Converters/DompdfAdapter.php:13-18` — S · low
  Switching adapters silently ignores half of the caller's options.

### API / UX

- [ ] **UX-01** Add `getTitles()`/`setTitles()` aware of EPUB 3 `title-type` (main/subtitle) — `src/Traits/InteractsWithTitle.php` — S · low
  Deferred from the previous round; a book whose subtitle comes first returns it as the title.
- [ ] **UX-02** Expose dated events (EPUB 2 `opf:event`, EPUB 3 `dcterms:modified`) instead of only the first `dc:date` — `src/Traits/InteractsWithDate.php:12-22` — S · low
  `getDate()` may return the modification date rather than the publication date.
- [ ] **UX-03** Add list APIs for repeated `<meta name>` / `<meta property>` entries — `src/Metadata.php:94-153` — S · low
  `setMeta()`/`setProperty()` only update the first match, so duplicates keep the old value alongside the new one.
- [ ] **UX-04** Extend guessed media types (aac, ogg, opus, m4v, webm, avif, json, xml, txt, vtt) and use `text/javascript` — `src/Manifest.php:15-36` — S · low
  Common EPUB 3 media fall back to `application/octet-stream`.

### Features

- [ ] **FEAT-01** Contributors and creator roles (`dc:contributor`, `opf:role` / role refinements); make `getAuthors()` honour roles — `src/Traits/InteractsWithAuthors.php:14-31` — M · med
  `getAuthors()` returns illustrators and editors too, and `dc:contributor` is unreachable.
- [ ] **FEAT-02** Table-of-contents API: read and edit the EPUB 3 nav document and the EPUB 2 NCX — new, `src/Parser.php:134-149` — L · med
  Callers cannot list chapters or add a new one to the TOC after `addContent()`.
- [ ] **FEAT-03** Validation API that returns a list of problems (structure, manifest/spine references, required metadata) — `src/Parser.php` — L · med
  `Parser` only throws on the first fatal problem, so callers cannot check a book or their own edits before publishing.
- [ ] **FEAT-04** Create a new EPUB from scratch (promote `tests/Support/EpubBuilder` to a public builder) — `tests/Support/EpubBuilder.php`, `src/EpubFile.php` — M · med
  The library can only edit existing books, and the test suite already holds most of the code.
- [ ] **FEAT-05** Deterministic archives on save (sorted entries, fixed timestamps) — `src/ZipHandler.php:199-223` — S · low
  The same book saved twice differs byte for byte.
- [ ] **FEAT-06** PDF bookmarks/outline from the spine or TOC — `src/Converters/TCPDFAdapter.php:92-95`, `src/Converters/DompdfAdapter.php:97-107` — M · low
  Generated PDFs have no navigation.

### Documentation

- [ ] **DOC-01** Refresh the README: `EpubFile::open()`/`convert()`, cover, manifest/spine editing, exceptions, and an accurate "Validation" claim — `README.md:11-60` — S · low
  It still shows `load()` plus converting an extracted directory and advertises content validation the library does not do.

### Tests

- [ ] **TEST-01** Run EPUBCheck in CI on books the library saved after edits (metadata, content, cover) — `.github/workflows/tests.yml`, `tests/EpubFileTest.php` — M · med
  Nothing checks saved books against the reference validator; BUG-03, BUG-11 and BUG-12 are exactly what it reports.

### CI and packaging

- [ ] **CI-01** Test the lowest supported dependencies (`composer update --prefer-lowest`, TCPDF 6.8) — `.github/workflows/tests.yml`, `composer.json` — S · med
  `tecnickcom/tcpdf: ^6.8 || ^7.0` is allowed but CI always resolves 7.x, so the TCPDF 6 path is never run.
- [ ] **CI-02** Install Calibre in one CI job so the skipped real-conversion tests run — `tests/Converters/CalibreAdapterRealTest.php:26`, `.github/workflows/tests.yml` — S · low
  The adapter is only ever tested against a mocked `exec()`.
- [ ] **CI-03** Build the docs with `mkdocs build --strict` on pull requests — `.github/workflows/deploy-docs.yml:47` — S · low
  Broken nav entries and links are only noticed after they reach the published site.
- [ ] **CI-04** Declare the extensions the library uses (`ext-simplexml`, `ext-libxml`, `ext-ctype`) in `require` — `composer.json`, `src/Manifest.php:298`, `src/XmlParser.php:38-41` — S · low
  Minimal builds (e.g. Alpine's split packages) install cleanly and then fail at runtime.

### Sweeps

- [x] **SWEEP-01** Validate every string written into the OPF (metadata values, `setMeta`/`setProperty`, manifest ids and media types) as UTF-8 with only XML 1.0 characters, and throw otherwise — `src/Metadata.php:104-153,253-263`, `src/Manifest.php:110-129` — S · high
  Probe: a Latin-1 title is written as raw bytes into the UTF-8 OPF, which then fails to parse, so the saved book cannot be reopened; control characters are stripped by `setText()` and make `addChild()` drop the whole value.
- [ ] **SWEEP-02** Close the remaining coverage holes: `ZipHandler` (3/6 methods), `FileSystemHelper` (77.8% lines), `ContentManager` (6/8 methods), `TCPDFAdapter` (5/6), `EpubFile` (14/15) — `tests/` — M · low
  These are the error paths left after round one (failed writes, unreadable entries, cleanup failures).
