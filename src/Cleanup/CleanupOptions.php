<?php

declare(strict_types=1);

namespace PhpEpub\Cleanup;

use PhpEpub\Exception;

/**
 * What Cleanup (and EpubFile::compress()) does to a book. Every action is off unless enabled;
 * preset() gives the usual combinations.
 */
final readonly class CleanupOptions
{
    /**
     * @param bool $removeUnreferenced Remove manifest items (and their files) that nothing reachable refers to.
     *                                 The spine items, the navigation document, the NCX and the cover are never removed.
     * @param bool $removeStrayFiles Remove files that are not in the manifest and not needed (the mimetype file,
     *                               META-INF/ and the package document are kept, as are files a reachable document refers to).
     * @param bool $stripScripts Remove <script> elements, inline event-handler attributes and javascript: URLs
     *                           from XHTML and SVG documents, and update the "scripted" properties.
     * @param bool $removeRemoteReferences Remove references to http(s) resources (images, media, stylesheets, @import,
     *                                     url()), and update the "remote-resources" properties. Links (<a>) are kept.
     * @param bool $removeUnusedFonts Remove font files that no reachable document or stylesheet refers to.
     * @param bool $recompressImages Recompress JPEG and PNG images (needs the GD extension; skipped without it).
     * @param int|null $maxImageWidth Images wider than this are scaled down (never up). Null for no limit.
     * @param int|null $maxImageHeight Images taller than this are scaled down (never up). Null for no limit.
     * @param int $jpegQuality JPEG quality, 1 to 100.
     * @param bool $convertOpaquePngToJpeg Convert PNG images without transparency to JPEG when that is smaller
     *                                     (renames the file and updates the manifest and every reference).
     * @param bool $maxDeflate Repack the archive at maximum deflate level (EpubFile::compress()).
     * @param bool $dryRun Only report what would happen: the book is left unchanged.
     *
     * @throws Exception If a size or the quality is out of range.
     */
    public function __construct(
        public bool $removeUnreferenced = false,
        public bool $removeStrayFiles = false,
        public bool $stripScripts = false,
        public bool $removeRemoteReferences = false,
        public bool $removeUnusedFonts = false,
        public bool $recompressImages = false,
        public ?int $maxImageWidth = null,
        public ?int $maxImageHeight = null,
        public int $jpegQuality = 80,
        public bool $convertOpaquePngToJpeg = false,
        public bool $maxDeflate = false,
        public bool $dryRun = false
    ) {
        ($maxImageWidth === null || $maxImageWidth >= 1) && ($maxImageHeight === null || $maxImageHeight >= 1)
            || throw new Exception('The maximum image width and height must be at least 1.');
        ($jpegQuality >= 1 && $jpegQuality <= 100) || throw new Exception('The JPEG quality must be between 1 and 100.');
    }

    public static function preset(CleanupPreset $preset): self
    {
        return match ($preset) {
            CleanupPreset::Light => new self(removeUnreferenced: true, removeStrayFiles: true, maxDeflate: true),
            CleanupPreset::Balanced => new self(
                removeUnreferenced: true,
                removeStrayFiles: true,
                recompressImages: true,
                maxImageWidth: 1600,
                maxImageHeight: 1600,
                jpegQuality: 80,
                maxDeflate: true
            ),
            CleanupPreset::Strong => new self(
                removeUnreferenced: true,
                removeStrayFiles: true,
                recompressImages: true,
                maxImageWidth: 1200,
                maxImageHeight: 1200,
                jpegQuality: 65,
                convertOpaquePngToJpeg: true,
                maxDeflate: true
            ),
        };
    }

    public function withDryRun(bool $dryRun = true): self
    {
        return new self(
            $this->removeUnreferenced,
            $this->removeStrayFiles,
            $this->stripScripts,
            $this->removeRemoteReferences,
            $this->removeUnusedFonts,
            $this->recompressImages,
            $this->maxImageWidth,
            $this->maxImageHeight,
            $this->jpegQuality,
            $this->convertOpaquePngToJpeg,
            $this->maxDeflate,
            $dryRun
        );
    }
}
