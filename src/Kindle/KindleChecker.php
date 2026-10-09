<?php

declare(strict_types=1);

namespace PhpEpub\Kindle;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\ManifestItem;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\PathResolver;
use PhpEpub\ValidationIssue;

/**
 * Checks a book against what Amazon documents for Send to Kindle and KDP. Every rule names its source in a comment;
 * a rule Amazon's documentation could not be tied to is a warning, marked "heuristic" here and in the docs.
 *
 * Amazon's pages change: this is a pre-flight check, and Kindle Previewer (KDP) stays the reference.
 */
final readonly class KindleChecker
{
    /**
     * Send to Kindle by web upload: "Max File Size: 200 MB".
     *
     * @see https://www.amazon.com/sendtokindle
     */
    private const int WEB_LIMIT_BYTES = 200 * 1024 * 1024;

    /**
     * Send to Kindle by email: "a total size of 50 MB or less".
     *
     * @see https://www.amazon.com/gp/help/customer/display.html?nodeId=G7NECT4B4ZWHQ8WV
     */
    private const int EMAIL_LIMIT_BYTES = 50 * 1024 * 1024;

    /**
     * KDP cover guidelines: "Your cover image must be less than 50MB", at least 1,000 pixels tall and 625 wide,
     * at most 10,000 pixels in height and width, a height/width ratio of at least 1.6:1, JPEG or TIFF.
     *
     * @see https://kdp.amazon.com/en_US/help/topic/G200645690
     */
    private const int COVER_MAX_BYTES = 50 * 1024 * 1024;

    private const int COVER_MIN_HEIGHT = 1000;

    private const int COVER_MIN_WIDTH = 625;

    private const int COVER_MAX_SIDE = 10000;

    private const float COVER_MIN_RATIO = 1.6;

    /**
     * Content documents above this size are not read for the content checks.
     */
    private const int MAX_DOCUMENT_BYTES = 8388608;

    /**
     * Spaces other than the normal space, the no-break space and the zero-width non-joiner (U+200C, which is
     * not in this list): Amazon says the others can break selection, dictionary lookup and line wrapping.
     * Includes the ideographic space and the narrow no-break space.
     *
     * @see https://kdp.amazon.com/en_US/help/topic/GH4DRT75GWWAGBTU
     * @see https://www.amazon.com/kindleformat
     */
    private const string UNSUPPORTED_SPACES = '/[\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u';

    private PathResolver $paths;

    /**
     * @param int $webLimitBytes The size above which the book is an error (Send to Kindle's web upload limit).
     * @param int $emailLimitBytes The size above which the book is a warning (Send to Kindle's email limit).
     */
    public function __construct(
        private EpubFile $epub,
        ?PathResolver $paths = null,
        private int $webLimitBytes = self::WEB_LIMIT_BYTES,
        private int $emailLimitBytes = self::EMAIL_LIMIT_BYTES
    ) {
        $this->paths = $paths ?? new PathResolver();
    }

    /**
     * @return list<ValidationIssue> Errors and warnings with codes starting with "KINDLE_", each with a fix hint.
     *
     * @throws Exception If the book is not loaded.
     */
    public function check(): array
    {
        $root = $this->epub->getTempDir() ?? throw new Exception('EPUB file must be loaded before checking it for Kindle.');

        return [
            ...$this->checkSize(),
            ...$this->checkDrm(),
            ...$this->checkMetadata(),
            ...$this->checkLayout(),
            ...$this->checkCover($root),
            ...$this->checkDocuments($root),
        ];
    }

    /**
     * Error above the 200 MB web upload limit; a warning above the 50 MB email limit. The size is that of the
     * packaged book, as save() would write it.
     *
     * @return list<ValidationIssue>
     */
    private function checkSize(): array
    {
        $stream = fopen('php://temp/maxmemory:8388608', 'w+b');
        if ($stream === false) {
            return [];
        }

        try {
            $this->epub->saveToStream($stream);
            $size = (int) (fstat($stream)['size'] ?? 0);
        } finally {
            fclose($stream);
        }

        $megabytes = round($size / 1048576, 1);
        if ($size > $this->webLimitBytes) {
            return [$this->issue(ValidationIssue::ERROR, 'KINDLE_FILE_TOO_LARGE', "The book is {$megabytes} MB; Send to Kindle accepts files of up to 200 MB.", null, 'Reduce the size of the images and remove unused media.')];
        }

        return $size > $this->emailLimitBytes
            ? [$this->issue(ValidationIssue::WARNING, 'KINDLE_FILE_TOO_LARGE_FOR_EMAIL', "The book is {$megabytes} MB; sending to Kindle by email allows 50 MB.", null, 'Upload it on the Send to Kindle web page or app instead, or reduce the size of the images.')]
            : [];
    }

    /**
     * Heuristic: Amazon's pages do not say whether Send to Kindle takes DRM-protected EPUBs, but their encrypted
     * content cannot be read by this library or converted by Amazon's pipeline as far as is documented.
     *
     * @return list<ValidationIssue>
     */
    private function checkDrm(): array
    {
        return $this->epub->isDrmProtected()
            ? [$this->issue(ValidationIssue::WARNING, 'KINDLE_DRM', 'The book is DRM-protected; heuristic: Send to Kindle is not documented to accept it.', 'META-INF/encryption.xml', 'Use a DRM-free copy of the book.')]
            : [];
    }

    /**
     * Heuristic: a Kindle library shows the title and the author; Amazon's pages consulted did not state that
     * metadata is required, and validate() already reports a missing title.
     *
     * @return list<ValidationIssue>
     */
    private function checkMetadata(): array
    {
        $authors = array_filter($this->epub->getMetadata()->getAuthors(), static fn (string $author): bool => trim($author) !== '');

        return $authors === []
            ? [$this->issue(ValidationIssue::WARNING, 'KINDLE_AUTHOR_MISSING', 'The book has no author (dc:creator); heuristic: the Kindle library lists books by author.', 'dc:creator', 'Add an author with Metadata::setAuthors().')]
            : [];
    }

    /**
     * Heuristic: Amazon's Send to Kindle pages do not say how fixed-layout EPUBs are handled (KDP accepts them as
     * EPUB, see https://kdp.amazon.com/en_US/help/topic/G200634390), so this only warns.
     *
     * @return list<ValidationIssue>
     */
    private function checkLayout(): array
    {
        return $this->epub->getMetadata()->getRenditionLayout() === 'pre-paginated'
            ? [$this->issue(ValidationIssue::WARNING, 'KINDLE_FIXED_LAYOUT', 'The book is fixed-layout (rendition:layout pre-paginated); heuristic: it may not display as designed through Send to Kindle.', 'rendition:layout', 'Check it in Kindle Previewer, or publish a reflowable edition.')]
            : [];
    }

    /**
     * A missing cover is a heuristic (Amazon's cover page documents the cover image to upload to KDP, not that
     * a book without one is refused). The format and size limits are those of the KDP cover image; they are
     * warnings because an EPUB's own cover is not the file uploaded to KDP.
     *
     * @return list<ValidationIssue>
     */
    private function checkCover(string $root): array
    {
        $cover = $this->epub->getCoverImage();
        if (! $cover instanceof ManifestItem) {
            return [$this->issue(ValidationIssue::WARNING, 'KINDLE_COVER_MISSING', 'The book has no cover image; heuristic: Kindle shows a generated cover instead.', null, 'Set one with EpubFile::setCoverImage() (a JPEG).')];
        }

        if (! str_starts_with($cover->mediaType, 'image/') || $cover->path === '') {
            return [];
        }

        $issues = [];
        if (! in_array($cover->mediaType, ['image/jpeg', 'image/tiff'], true)) {
            $issues[] = $this->issue(ValidationIssue::WARNING, 'KINDLE_COVER_TYPE', "The cover is {$cover->mediaType}; KDP takes cover images as JPEG or TIFF.", $cover->path, 'Convert the cover to JPEG.');
        }

        try {
            $file = $this->paths->resolve($root, $cover->path);
        } catch (Exception) {
            return $issues;
        }

        if (! is_file($file) || $cover->mediaType === 'image/svg+xml') {
            return $issues;
        }

        $bytes = (int) @filesize($file);
        $size = @getimagesize($file);
        if ($size !== false) {
            [$width, $height] = $size;
            if ($height < self::COVER_MIN_HEIGHT || $width < self::COVER_MIN_WIDTH) {
                $issues[] = $this->issue(ValidationIssue::WARNING, 'KINDLE_COVER_TOO_SMALL', "The cover is {$width} x {$height} pixels; KDP wants at least " . self::COVER_MIN_WIDTH . ' x ' . self::COVER_MIN_HEIGHT . ' (2,560 pixels tall by 1,600 wide is ideal).', $cover->path, 'Use a larger cover image.');
            }

            if ($height > self::COVER_MAX_SIDE || $width > self::COVER_MAX_SIDE) {
                $issues[] = $this->issue(ValidationIssue::WARNING, 'KINDLE_COVER_TOO_LARGE', "The cover is {$width} x {$height} pixels; KDP allows at most " . self::COVER_MAX_SIDE . ' pixels in height and width.', $cover->path, 'Scale the cover down.');
            }

            if ($width > 0 && $height / $width < self::COVER_MIN_RATIO) {
                $issues[] = $this->issue(ValidationIssue::WARNING, 'KINDLE_COVER_RATIO', 'The cover is not tall enough; KDP wants a height/width ratio of at least 1.6:1.', $cover->path, 'Use a cover of at least 1.6 times as tall as wide.');
            }
        }

        if ($bytes >= self::COVER_MAX_BYTES) {
            $issues[] = $this->issue(ValidationIssue::WARNING, 'KINDLE_COVER_TOO_LARGE', 'The cover file is 50 MB or more; KDP wants less than 50 MB.', $cover->path, 'Compress the cover image.');
        }

        return $issues;
    }

    /**
     * Content documents: UTF-8 (the Kindle Publishing Guidelines: characters "should" be plain UTF-8, and the only
     * supported spaces are the normal space, no-break space and zero-width non-joiner: both from the guidelines,
     * read through search summaries of the pages) and scripts (heuristic: not confirmed from an Amazon page).
     *
     * @return list<ValidationIssue>
     */
    private function checkDocuments(string $root): array
    {
        $encrypted = array_flip($this->epub->getEncryptedPaths());
        $issues = [];
        foreach ($this->epub->getManifest()->getItems() as $item) {
            if ($item->mediaType !== 'application/xhtml+xml' || $item->path === '' || isset($encrypted[$item->path])) {
                continue;
            }

            try {
                $file = $this->paths->resolve($root, $item->path);
            } catch (Exception) {
                continue;
            }

            $content = is_file($file) && (int) @filesize($file) <= self::MAX_DOCUMENT_BYTES ? FileSystemHelper::readFile($file) : null;
            if ($content === null) {
                continue;
            }

            $issues = [...$issues, ...$this->checkDocument($item, $content)];
        }

        return $issues;
    }

    /**
     * @return list<ValidationIssue>
     */
    private function checkDocument(ManifestItem $item, string $content): array
    {
        $issues = [];
        $isUtf16 = str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF");
        $declared = preg_match('/^\s*<\?xml[^>]*\sencoding\s*=\s*["\']([^"\']+)["\']/i', $content, $match) === 1 ? strtolower($match[1]) : 'utf-8';
        if ($isUtf16 || ! in_array($declared, ['utf-8', 'utf8'], true) || ! mb_check_encoding($content, 'UTF-8')) {
            return [$this->issue(ValidationIssue::WARNING, 'KINDLE_NOT_UTF8', 'The document is not UTF-8 text; the Kindle Publishing Guidelines ask for UTF-8 characters.', $item->path, 'Save the document as UTF-8 and declare it.')];
        }

        if (preg_match(self::UNSUPPORTED_SPACES, $content) === 1) {
            $issues[] = $this->issue(ValidationIssue::WARNING, 'KINDLE_UNSUPPORTED_SPACE', 'The document uses a space character Kindle does not support (only the space, no-break space and zero-width non-joiner are); it can break selection, dictionary lookup and line wrapping.', $item->path, 'Replace the special spaces with a normal or no-break space, or use CSS for spacing.');
        }

        // Heuristic: the pages consulted do not say whether scripts are dropped or the book refused.
        if (preg_match('/<script[\s>]/i', $content) === 1) {
            $issues[] = $this->issue(ValidationIssue::WARNING, 'KINDLE_SCRIPTED', 'The document contains a script; heuristic: Kindle reading systems are not documented to run scripts in reflowable books.', $item->path, 'Remove the script, or make sure the book reads without it.');
        }

        return $issues;
    }

    private function issue(string $severity, string $code, string $message, ?string $location, string $fix): ValidationIssue
    {
        return new ValidationIssue($severity, $code, $message, $location, $fix);
    }
}
