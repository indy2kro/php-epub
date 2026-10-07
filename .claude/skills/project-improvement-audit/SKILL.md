---
name: project-improvement-audit
description: Use when you want a project-wide audit of the php-epub library (every class under src/, converters, docs, tests, CI workflows) to discover many different improvement opportunities — correctness bugs, untrusted-input safety (ZIP/XML), converter gaps, docs drift, test/CI gaps, and new-feature ideas — compiled into a prioritized, checkbox backlog in docs/improvement-audit/. Discovery only; no source changes.
---

# Project Improvement Audit — php-epub

## Overview

Harvest mechanical signals from the real gates. Then go through the module
clusters one batch at a time (no subagents), dedupe and prioritize, and write a
dated backlog. **No code changes.** The deliverable is the report.

An EPUB is an untrusted ZIP of XML/HTML: treat every byte read from one as
hostile input, and treat every path or URL it contains as attacker controlled.

## Token budget

- **No subagents.** Run batches one after another in the main conversation.
- Up to **10 findings per batch**. Rationale is **1 sentence**.
- Skip pure code-style/refactor findings unless they have caused bugs (PHPCS,
  PHPStan and Rector already guard style).
- **Write each batch's findings to disk** (scratchpad `findings/batch-N.json`)
  as soon as the batch is done, then drop the raw grep/read output from context.
- `rg -g`/`rg --type` can get rewritten by the rtk hook and fail. Use the Grep
  tool or `rtk proxy rg ...` for glob-filtered searches.
- Never pipe a gate through `tail` and then chain on it: `| tail` hides the
  exit code. Capture the output to a file and check `$?` separately.

## Target count

Default **X = 30** deduplicated items (the library is about 60 source files). Honor
an explicit override. Never pad with filler.

## Security stop rule

If a finding is a **security vulnerability** (see the `sec` lens), do not
exploit it and do not build a proof of concept. Record it, stop the audit, tell
the user and ask them to inform information security. Continue only when the
user says to.

## Lenses

| Lens | Looks for |
|---|---|
| `bug` | Wrong behavior: metadata that does not round-trip (read, edit, save), spine / manifest / NCX / nav inconsistencies, EPUB 2 vs EPUB 3 differences, encoding and namespace mistakes, swallowed exceptions, `false`/`null` returns that callers do not handle |
| `sec` | Untrusted input: ZIP entry names (path traversal / zip slip when extracting), zip bombs and unbounded decompression, XML external entities and entity expansion, `libxml` options and error handling, user supplied paths and URLs (SSRF through `curl`), shell injection when calling external converters (Calibre), temp files in predictable locations |
| `conv` | Converters (`CalibreAdapter`, `TCPDFAdapter`, `DompdfAdapter`): missing options, error reporting, resource cleanup, behaviour when the external tool or extension is missing, font handling (`scripts/generate-core-fonts.php`) |
| `ux` | Awkward public API: nullable-everywhere returns, public mutable state, unclear exceptions, inconsistent naming across the `Interacts*` traits, missing helpers for common tasks |
| `feature` | Missing capabilities of real value: cover handling, TOC editing, creating an EPUB from scratch, validating (EPUBCheck-like rules), EPUB 3 media overlays, fixed layout, DRM-free font obfuscation, streaming large books |
| `docs` | `docs/` and `mkdocs.yml` drift from the code (every public class and option documented, examples that still run), README accuracy, install instructions |
| `test` / `ci` | Coverage holes, missing fixtures (EPUB 2/3, broken, huge, odd encodings), gates missing from `.github/workflows/tests.yml` |

Tie-break: `sec` > `bug` > `conv` > `feature` > `ux`.

## Pipeline

### 1. Mechanical harvest (repo root)

CI runs `composer update` first, so it uses newer PHPStan / Rector than a stale
`vendor/`. Reproduce CI, then restore the lock file:

```sh
composer update --no-progress                 # restore composer.lock afterwards: git checkout composer.lock
vendor/bin/phpunit --coverage-text            # needs pcov or xdebug for coverage
vendor/bin/phpcs src/ tests/ docs/
php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress
vendor/bin/rector --dry-run
composer audit ; composer outdated --direct
composer validate --strict
gitleaks detect --no-banner -s .
actionlint
```

(`composer quality` runs the four gates in order.) Aggregate the results as
`SWEEP-*` items (e.g. "add tests for classes X, Y, Z"), not one row per file.

### 2. Batches (module clusters)

1. `src/EpubFile.php`, `src/*Exception.php`, `src/ZipHandler.php`, `src/Util/FileSystemHelper.php`, `src/Util/PathResolver.php`
2. `src/Parser.php`, `src/XmlParser.php`, `src/Manifest.php`, `src/Spine.php`, `src/Encryption.php`, `src/FontObfuscation.php`
3. `src/Metadata.php`, `src/Traits/`, `src/Util/MetadataSyntax.php`, `src/Util/PackagePrefixes.php`, `src/Util/Rendition.php`, `src/Util/XmlText.php`
4. `src/ContentManager.php`, `src/TableOfContents.php`, `src/Landmark.php`, `src/BookTemplate.php`, the remaining `src/Util/` classes
5. `src/Validator.php`, `src/Converter.php`, `src/Converters/`, `scripts/`
6. `docs/`, `mkdocs.yml`, `README.md`, `AGENTS.md`, `tests/`, `tests/fixtures/`, `.github/workflows/`, `composer.json`

For each batch, grep first and read only what a hit calls for. Useful probes:
`ZipArchive` / `extractTo` / `getNameIndex`, `getFromName` with unchecked names,
`DOMDocument` / `simplexml_load_*` / `libxml_*` / `LIBXML_NOENT`, `file_get_contents`
and `curl_` on external values, `exec` / `shell_exec` / `proc_open` / `escapeshellarg`,
`tempnam` / `sys_get_temp_dir`, `@` error suppression, `catch (Exception` that
swallows, `public` properties, `false` returns, `mb_*` vs byte functions,
`DIRECTORY_SEPARATOR` vs `/` inside ZIP paths. Check each claim against the code
(and with a small test when it is cheap) before recording it.

Finding schema (one JSON array per batch):

```json
{"t":"Short imperative summary","cat":"core|zip|xml|metadata|content|converter|docs|ci","l":"bug|sec|conv|ux|feature|docs|test","f":["src/x.php:12-30"],"e":"S|M|L","i":"low|med|high","r":"One sentence why it matters.","mode":"single|sweep"}
```

`e`: S = one file, under 30 min. M = one class plus tests. L = cross-cutting.
`i`: high = wrong output, data loss, crash, or a security issue on a realistic book. med = common path. low = nice-to-have.

### 3. Dedupe & prioritize

Merge the batch files. Collapse duplicates. Promote anything that spans three or
more classes to `SWEEP-*`. Rank high-impact/low-effort first. Compare against the
previous backlog (`git log -- docs/improvement-audit/`) so already-fixed items are
not re-reported. If the total is below X, redo the thinnest batches.

### 4. Write the backlog

`docs/improvement-audit/YYYY-MM-DD-improvement-backlog.md`. Use stable IDs
(`SWEEP-NN`, `BUG-NN`, `SEC-NN`, `CONV-NN`, `UX-NN`, `FEAT-NN`, `DOC-NN`, `TEST-NN`,
`CI-NN`), make every item a `- [ ]`, and reuse the header, summary table and
execution-instructions layout of the previous round if one exists. Commit it with
no source changes and delete the scratch batch files.

## Executing a backlog (later rounds)

- One branch and one PR per theme, stacked when they touch the same files.
- Write the failing test first. For ZIP/XML issues build the hostile EPUB in the
  test (a tiny generator in `tests/Support/`) instead of committing binary fixtures.
- Before every push run the gates **against fresh dependencies** (see step 1),
  because CI does, and check each exit code.
- Remove `docs/improvement-audit/` once everything in it is implemented or
  explicitly declined (note the declined items in the PR description).

## Done criteria

- [ ] ≥ X deduplicated items, each with an ID, checkbox, `file:line`, effort, impact and rationale
- [ ] Mechanical gaps aggregated as sweep items
- [ ] No duplicates against each other or against previously completed backlogs
- [ ] Report written under `docs/improvement-audit/`; no source changes
