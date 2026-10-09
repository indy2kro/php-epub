# Checking a book for Kindle

`ValidationProfile::kindle()` adds checks for sending a book to a Kindle (Send to Kindle) or publishing it with Amazon KDP. Pass it to [`validate()`](epub-file.md#validating): the issues follow the structural ones, are ordinary `ValidationIssue` objects, and have a `KINDLE_` code and a `fix` hint.

```php
use PhpEpub\EpubFile;
use PhpEpub\ValidationProfile;

$epubFile = EpubFile::open('/path/to/book.epub');

foreach ($epubFile->validate(ValidationProfile::kindle()) as $issue) {
    echo $issue, "\n";
    if ($issue->fix !== null) {
        echo '  Fix: ', $issue->fix, "\n";
    }
}
```

`PhpEpub\Kindle\KindleChecker` does the checks: `(new KindleChecker($epubFile))->check()` returns only the Kindle issues. Amazon's documentation changes and is not a specification: treat this as a pre-flight check and use Kindle Previewer as the reference.

## Rules and sources

A rule is **sourced** when a figure or requirement comes from an Amazon page; a **heuristic** rule could not be tied to an Amazon page and is always a warning. The URL of each source is in a comment next to the rule in `src/Kindle/KindleChecker.php`.

| Code | Severity | Rule | Source |
|---|---|---|---|
| `KINDLE_FILE_TOO_LARGE` | error | The packaged book is above 200 MB, the web upload limit | [Send to Kindle](https://www.amazon.com/sendtokindle) |
| `KINDLE_FILE_TOO_LARGE_FOR_EMAIL` | warning | Above 50 MB, the limit for sending by email | [Send to Kindle by email](https://www.amazon.com/gp/help/customer/display.html?nodeId=G7NECT4B4ZWHQ8WV) |
| `KINDLE_COVER_TYPE` | warning | The cover is not a JPEG (or TIFF) | [KDP cover guidelines](https://kdp.amazon.com/en_US/help/topic/G200645690) |
| `KINDLE_COVER_TOO_SMALL` | warning | The cover is under 625 x 1,000 pixels (2,560 x 1,600 is ideal) | KDP cover guidelines |
| `KINDLE_COVER_TOO_LARGE` | warning | The cover is above 10,000 pixels in height or width, or 50 MB or more | KDP cover guidelines |
| `KINDLE_COVER_RATIO` | warning | The cover's height/width ratio is under 1.6:1 | KDP cover guidelines |
| `KINDLE_NOT_UTF8` | warning | A content document is UTF-16, declares another encoding or is not valid UTF-8 | [Kindle Publishing Guidelines](https://kdp.amazon.com/en_US/help/topic/GH4DRT75GWWAGBTU) (characters should be UTF-8) |
| `KINDLE_UNSUPPORTED_SPACE` | warning | A content document uses a space other than the space, no-break space and zero-width non-joiner (e.g. the thin space or the ideographic space) | Kindle Publishing Guidelines, [kindleformat](https://www.amazon.com/kindleformat) |
| `KINDLE_COVER_MISSING` | warning | The book declares no cover | heuristic |
| `KINDLE_AUTHOR_MISSING` | warning | The book has no `dc:creator` | heuristic |
| `KINDLE_FIXED_LAYOUT` | warning | `rendition:layout` is `pre-paginated`; KDP accepts fixed-layout EPUB, but no Send to Kindle page says how it is handled | heuristic |
| `KINDLE_SCRIPTED` | warning | A content document contains a `<script>` | heuristic |
| `KINDLE_DRM` | warning | The book is DRM-protected; no page says whether Send to Kindle takes it | heuristic |

The cover rules are those of the cover image uploaded to KDP, which is not always the cover inside the EPUB, so they are warnings. The size is that of the book's files packed into a scratch archive that is removed again; nothing in the book changes (unsaved package edits, a few hundred bytes, are not counted). Encrypted content is not read. Only the Send to Kindle size limits and the cover figures were checked against Amazon search results and the KDP cover page; the UTF-8 and space rules come from search summaries of the guidelines, which could not be fetched in full.
