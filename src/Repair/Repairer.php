<?php

declare(strict_types=1);

namespace PhpEpub\Repair;

use PhpEpub\BookTemplate;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Manifest;
use PhpEpub\ManifestItem;
use PhpEpub\TocEntry;
use PhpEpub\Util\PathResolver;
use PhpEpub\ValidationIssue;

/**
 * Fixes the problems EpubFile::validate() reports that have one safe, deterministic fix. The changes are made to
 * the loaded book (EpubFile::save() writes them) and every change is returned as an AppliedFix.
 *
 * A repair never deletes a file of the book: a manifest item whose file is missing leaves the manifest, but a
 * file the manifest does not list is added to it. Problems without a safe fix (a title, broken XML, content
 * documents that refer to missing files) are left for validate() to keep reporting.
 */
final class Repairer
{
    private const string NCX_MEDIA_TYPE = 'application/x-dtbncx+xml';

    private const string XHTML_MEDIA_TYPE = 'application/xhtml+xml';

    private const array IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const int SNIFF_BYTES = 524288;

    /**
     * Files that editors and operating systems leave in a book folder: never listed in a manifest.
     */
    private const string JUNK = '#(^|/)(\.[^/]*|Thumbs\.db|desktop\.ini|__MACOSX(/.*)?)$|^(iTunesMetadata\.plist|iTunesArtwork)$#i';

    /**
     * @var list<AppliedFix>
     */
    private array $applied = [];

    public function __construct(
        private readonly EpubFile $epub,
        private readonly PathResolver $paths = new PathResolver()
    ) {
    }

    /**
     * Applies the selected repairs.
     *
     * @param RepairOptions|null $options The repairs and default values; all default repairs when null.
     *
     * @return list<AppliedFix> The changes made, in the order they were made; empty when the book needed none.
     *
     * @throws Exception If the book is not loaded or a file cannot be written.
     */
    public function repair(?RepairOptions $options = null): array
    {
        $options ??= new RepairOptions();
        $root = $this->epub->getTempDir() ?? throw new Exception('EPUB file must be loaded before repairing.');
        $this->applied = [];

        $issues = $options->includes(RepairFix::UnlistedFiles) || $options->includes(RepairFix::MediaTypes) ? $this->epub->validate() : [];

        $this->when($options, RepairFix::DuplicateIds, $this->repairDuplicateIds(...));
        // These address manifest items by id: with a repeated id they would change the wrong item, so they wait
        // until DuplicateIds has made the ids unique.
        $ambiguous = ! $options->includes(RepairFix::DuplicateIds) && $this->hasDuplicateIds();
        $options = $ambiguous ? $options->without(RepairFix::MissingFiles, RepairFix::MediaTypes, RepairFix::Cover, RepairFix::UpgradeToEpub3, RepairFix::ManifestProperties) : $options;
        $this->when($options, RepairFix::MissingFiles, fn () => $this->repairMissingFiles($root));
        $this->when($options, RepairFix::SpineReferences, $this->repairSpine(...));
        $this->when($options, RepairFix::UnlistedFiles, fn () => $this->repairUnlistedFiles($root, $issues));
        $this->when($options, RepairFix::MediaTypes, fn () => $this->repairMediaTypes($root, $issues));
        $this->when($options, RepairFix::Cover, fn () => $this->repairCover($root));
        $this->when($options, RepairFix::UpgradeToEpub3, $this->upgrade(...));

        $codes = $this->codes($options);
        $this->when($options, RepairFix::Language, fn () => $this->repairLanguage($codes, $options->defaultLanguage));
        $this->when($options, RepairFix::Identifier, fn () => $this->repairIdentifier($codes));
        $this->when($options, RepairFix::ModifiedDate, fn () => $this->repairModifiedDate($codes, $options));
        $this->when($options, RepairFix::Navigation, fn () => $this->createNavigation($codes, $options->defaultLanguage));
        $this->when($options, RepairFix::TocLinks, $this->repairTocLinks(...));
        $this->when($options, RepairFix::Navigation, $this->fillEmptyToc(...));
        $this->when($options, RepairFix::ManifestProperties, $this->repairManifestProperties(...));

        return $this->applied;
    }

    /**
     * @param \Closure(): mixed $repair
     */
    private function when(RepairOptions $options, RepairFix $fix, \Closure $repair): void
    {
        if ($options->includes($fix)) {
            $repair();
        }
    }

    /**
     * The codes validate() reports now, when a selected repair is driven by one of the metadata and navigation codes.
     *
     * @return array<string, true>
     */
    private function codes(RepairOptions $options): array
    {
        $needed = [RepairFix::Language, RepairFix::Identifier, RepairFix::ModifiedDate, RepairFix::Navigation];
        if (array_filter($needed, $options->includes(...)) === []) {
            return [];
        }

        $codes = [];
        foreach ($this->epub->validate() as $issue) {
            $codes[$issue->code] = true;
        }

        return $codes;
    }

    private function fixed(string $code, string $description, ?string $location = null): void
    {
        $this->applied[] = new AppliedFix($code, $description, $location);
    }

    private function hasDuplicateIds(): bool
    {
        $ids = array_map(static fn (ManifestItem $item): string => $item->id, $this->epub->getManifest()->getItems());

        return count(array_unique($ids)) !== count($ids);
    }

    private function repairDuplicateIds(): void
    {
        foreach ($this->epub->getManifest()->renameDuplicateIds() as [$old, $new, $path]) {
            $this->fixed('DUPLICATE_ID', "Renamed the manifest item with the repeated id \"{$old}\" to \"{$new}\"; the spine keeps pointing at the first one.", $path === '' ? $new : $path);
        }
    }

    private function repairMissingFiles(string $root): void
    {
        $manifest = $this->epub->getManifest();
        $spine = $this->epub->getSpine();
        foreach ($manifest->getItems() as $item) {
            if ($item->path === '' || $this->fileExists($root, $item->path)) {
                continue;
            }

            while ($spine->contains($item->id)) {
                $spine->remove($item->id);
            }

            $manifest->remove($item->id);
            $this->fixed('MANIFEST_FILE_MISSING', "Removed the manifest item \"{$item->id}\", which has no file, from the manifest and the spine.", $item->path);
        }
    }

    private function repairSpine(): void
    {
        $spine = $this->epub->getSpine();
        $seen = [];
        $remove = [];
        foreach ($spine->getItems() as $position => $spineItem) {
            if (! $spineItem->item instanceof ManifestItem) {
                $remove[$position] = ['SPINE_UNKNOWN_IDREF', "Removed the spine reference to \"{$spineItem->idref}\", which is not in the manifest.", $spineItem->idref];
            } elseif (isset($seen[$spineItem->idref])) {
                $remove[$position] = ['SPINE_DUPLICATE_IDREF', "Removed a repeated spine reference to \"{$spineItem->idref}\"; the first one stays.", $spineItem->idref];
            }

            $seen[$spineItem->idref] = true;
        }

        // From the end, so the positions still to remove stay valid.
        foreach (array_reverse($remove, true) as $position => [$code, $description, $idref]) {
            $spine->removeAt($position);
            $this->fixed($code, $description, $idref);
        }
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    private function repairUnlistedFiles(string $root, array $issues): void
    {
        $manifest = $this->epub->getManifest();
        foreach ($issues as $issue) {
            $path = (string) $issue->location;
            if ($issue->code !== 'FILE_NOT_IN_MANIFEST' || $path === '' || preg_match(self::JUNK, $path) === 1 || $manifest->findByPath($path) instanceof ManifestItem) {
                continue;
            }

            $mediaType = $this->sniffImage($root, $path) ?? $manifest->guessMediaType($path);
            $manifest->add($path, $mediaType);

            $note = $mediaType === self::XHTML_MEDIA_TYPE ? ' It is not in the spine: add it there to make it part of the reading order.' : '';
            $this->fixed('FILE_NOT_IN_MANIFEST', "Added the file to the manifest as {$mediaType}.{$note}", $path);
        }
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    private function repairMediaTypes(string $root, array $issues): void
    {
        $manifest = $this->epub->getManifest();
        foreach ($issues as $issue) {
            $item = $issue->code === 'MEDIA_TYPE_MISMATCH' ? $manifest->findByPath((string) $issue->location) : null;
            if (! $item instanceof ManifestItem) {
                continue;
            }

            // A document of the reading order never becomes a non-content type: that would trade one problem for another.
            $inSpine = $this->epub->getSpine()->contains($item->id);
            $mediaType = $item->mediaType === self::XHTML_MEDIA_TYPE ? ($inSpine ? null : $manifest->guessMediaType($item->path)) : $this->sniffImage($root, $item->path);
            if ($mediaType === null || $mediaType === $item->mediaType || $mediaType === self::XHTML_MEDIA_TYPE || $mediaType === 'application/octet-stream') {
                continue;
            }

            $manifest->setMediaType($item->id, $mediaType);
            $this->fixed('MEDIA_TYPE_MISMATCH', "Changed the media type of \"{$item->id}\" from {$item->mediaType} to {$mediaType}.", $item->path);
        }
    }

    private function repairCover(string $root): void
    {
        $manifest = $this->epub->getManifest();
        $metadata = $this->epub->getMetadata();

        foreach ($manifest->getItems() as $item) {
            if (in_array('cover-image', $this->properties($item), true) && ! str_starts_with($item->mediaType, 'image/')) {
                $manifest->removeProperty($item->id, 'cover-image');
                $this->fixed('COVER_NOT_IMAGE', "Removed the cover-image property from \"{$item->id}\" ({$item->mediaType}), which is not an image.", $item->path);
            }
        }

        $cover = $this->epub->getCoverImage();
        $declared = $cover instanceof ManifestItem;
        $cover ??= $this->obviousCover($manifest, $root);
        if (! $cover instanceof ManifestItem || ! str_starts_with($cover->mediaType, 'image/')) {
            return;
        }

        $missing = [];
        if ($manifest->isEpub3() && ! in_array('cover-image', $this->properties($cover), true)) {
            $manifest->addProperty($cover->id, 'cover-image');
            $missing[] = 'the cover-image property';
        }

        if ($metadata->getMeta('cover') !== $cover->id) {
            $metadata->setMeta('cover', $cover->id);
            $missing[] = 'the cover meta';
        }

        if ($missing !== []) {
            $this->fixed(
                $declared ? 'COVER_NOT_FLAGGED' : 'COVER_NOT_DECLARED',
                ($declared ? 'The cover image was only declared in part' : 'The book declared no cover image, but this one is named like one')
                . '; added ' . implode(' and ', $missing) . '.',
                $cover->path
            );
        }
    }

    /**
     * The image of the manifest named like a cover ("cover.jpg", "cover-image.png"), the plainest name first.
     */
    private function obviousCover(Manifest $manifest, string $root): ?ManifestItem
    {
        $found = null;
        foreach ($manifest->getItems() as $item) {
            $stem = strtolower(pathinfo($item->path, PATHINFO_FILENAME));
            if ($item->path === '' || ! in_array($item->mediaType, self::IMAGE_TYPES, true) || preg_match('/^cover([-_]?image)?$/', $stem) !== 1 || $this->sniffImage($root, $item->path) === null) {
                continue;
            }

            if ($stem === 'cover') {
                return $item;
            }

            $found ??= $item;
        }

        return $found;
    }

    private function upgrade(): void
    {
        if ($this->epub->upgradeToEpub3()) {
            $this->fixed('UPGRADED_TO_EPUB3', 'Converted the EPUB 2 book to EPUB 3.');
        }
    }

    /**
     * @param array<string, true> $codes
     */
    private function repairLanguage(array $codes, string $language): void
    {
        if (isset($codes['METADATA_LANGUAGE_MISSING'])) {
            $this->epub->getMetadata()->setLanguage($language);
            $this->fixed('METADATA_LANGUAGE_MISSING', "Set the language to \"{$language}\".", 'dc:language');
        }
    }

    /**
     * @param array<string, true> $codes
     */
    private function repairIdentifier(array $codes): void
    {
        $code = isset($codes['METADATA_IDENTIFIER_MISSING']) ? 'METADATA_IDENTIFIER_MISSING' : (isset($codes['METADATA_UNIQUE_IDENTIFIER']) ? 'METADATA_UNIQUE_IDENTIFIER' : null);
        if ($code === null) {
            return;
        }

        $identifier = BookTemplate::uuidUrn();
        if ($this->epub->getMetadata()->ensureUniqueIdentifier($identifier)) {
            $now = $this->epub->getMetadata()->getUniqueIdentifier();
            $this->fixed($code, 'The package now has a unique identifier' . ($now === $identifier ? ": {$identifier}." : " (\"{$now}\")."), 'dc:identifier');
        }
    }

    /**
     * @param array<string, true> $codes
     */
    private function repairModifiedDate(array $codes, RepairOptions $options): void
    {
        if (! isset($codes['METADATA_MODIFIED_MISSING'])) {
            return;
        }

        $now = ($options->now ?? new \DateTimeImmutable())->getTimestamp();
        $modified = gmdate('Y-m-d\TH:i:s\Z', $now);
        $this->epub->getMetadata()->setProperty('dcterms:modified', $modified);
        $this->fixed('METADATA_MODIFIED_MISSING', "Set dcterms:modified to {$modified}.", 'dcterms:modified');
    }

    /**
     * @param array<string, true> $codes
     */
    private function createNavigation(array $codes, string $defaultLanguage): void
    {
        $toc = $this->epub->getTableOfContents();
        $metadata = $this->epub->getMetadata();
        $title = trim($metadata->getTitle());
        if (isset($codes['NAV_MISSING'])) {
            $language = trim($metadata->getLanguage());
            $hasNcx = $this->ncxPath() !== null;
            $toc->createNavigation($title === '' ? 'Contents' : $title, $language === '' ? $defaultLanguage : $language);
            if (! $hasNcx) {
                $this->titleFromHeadings();
            }

            $this->fixed('NAV_MISSING', 'Created a navigation document.', $this->navPath());
        }

        if (isset($codes['NCX_MISSING'])) {
            $path = $this->createNcx($title);
            if ($path !== null) {
                $this->fixed('NCX_MISSING', 'Created an NCX from the reading order.', $path);
            }
        }
    }

    /**
     * Writes an NCX that holds one entry per document of the reading order; null when the book has none.
     */
    private function createNcx(string $title): ?string
    {
        $manifest = $this->epub->getManifest();
        $entries = $this->readingOrderEntries();
        if ($entries === []) {
            return null;
        }

        $directory = dirname($manifest->getOpfPath());
        $base = $directory === '.' ? '' : $directory . '/';
        $root = (string) $this->epub->getTempDir();
        for ($number = 1;; $number++) {
            $path = $base . ($number === 1 ? 'toc' : 'toc-' . $number) . '.ncx';
            if (! $manifest->findByPath($path) instanceof ManifestItem && ! file_exists($this->paths->resolve($root, $path))) {
                break;
            }
        }

        $shell = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head/><docTitle><text/></docTitle><navMap/></ncx>' . "\n";
        // A disk write failure cannot be made in a test.
        // @codeCoverageIgnoreStart
        if (@file_put_contents($this->paths->resolve($root, $path), $shell) === false) {
            throw new Exception("Failed to write the NCX: {$path}");
        }
        // @codeCoverageIgnoreEnd

        $item = $manifest->add($path, self::NCX_MEDIA_TYPE);
        $this->epub->getSpine()->setToc($item->id);
        $toc = $this->epub->getTableOfContents();
        $toc->syncNcx($title, $this->epub->getMetadata()->getUniqueIdentifier());
        $toc->setEntries($entries);
        $this->titleFromHeadings();

        return $path;
    }

    /**
     * Replaces a table of contents made of file names by the headings of the reading order, when it has some.
     */
    private function titleFromHeadings(): void
    {
        try {
            $this->epub->getTableOfContents()->generateFromHeadings();
        } catch (Exception) {
            // No headings: the file names stay.
        }
    }

    private function repairTocLinks(): void
    {
        $toc = $this->epub->getTableOfContents();
        $manifest = $this->epub->getManifest();
        try {
            $entries = $toc->getEntries();
        } catch (Exception) {
            // A navigation document or NCX that cannot be read is reported by validate().
            return;
        }

        $dropped = [];
        $kept = $this->entriesInManifest($entries, $manifest, $dropped);
        if ($dropped === []) {
            return;
        }

        $toc->writeEntries($kept);
        foreach ($dropped as $path) {
            $this->fixed('TOC_LINK_NOT_IN_MANIFEST', 'Removed the table-of-contents entry that links to a file that is not in the book.', $path);
        }
    }

    /**
     * The entries without those that link to a file outside the manifest; an entry with children stays as an
     * unlinked heading, as when ContentManager deletes the file.
     *
     * @param list<TocEntry> $entries
     * @param list<string> $dropped The paths of the removed links.
     *
     * @return list<TocEntry>
     */
    private function entriesInManifest(array $entries, Manifest $manifest, array &$dropped): array
    {
        $kept = [];
        foreach ($entries as $entry) {
            $children = $this->entriesInManifest($entry->children, $manifest, $dropped);
            if ($entry->path === '' || $manifest->findByPath($entry->path) instanceof ManifestItem) {
                $kept[] = new TocEntry($entry->title, $entry->path, $entry->fragment, $children);
                continue;
            }

            $dropped[] = $entry->path;
            if ($children !== []) {
                $kept[] = new TocEntry($entry->title, '', null, $children);
            }
        }

        return $kept;
    }

    /**
     * A table of contents without an entry is built from the headings of the reading order, else from its documents.
     */
    private function fillEmptyToc(): void
    {
        $toc = $this->epub->getTableOfContents();
        if (! $toc->isAvailable()) {
            return;
        }

        try {
            if ($toc->getEntries() !== []) {
                return;
            }

            $path = $this->navPath() ?? $this->ncxPath();
            try {
                $toc->generateFromHeadings();
                $source = 'its headings';
            } catch (Exception) {
                $entries = $this->readingOrderEntries();
                if ($entries === []) {
                    return;
                }

                $toc->setEntries($entries);
                $source = 'the reading order';
            }
        } catch (Exception) {
            // A navigation document or NCX that cannot be read is reported by validate().
            return;
        }

        $this->fixed($this->navPath() !== null ? 'NAV_EMPTY' : 'NCX_EMPTY', "Filled the empty table of contents from {$source}.", $path);
    }

    private function repairManifestProperties(): void
    {
        $manifest = $this->epub->getManifest();
        $before = [];
        foreach ($manifest->getItems() as $item) {
            $before[$item->id] = $this->properties($item);
        }

        $this->epub->getContentManager()->updateManifestProperties();

        foreach ($manifest->getItems() as $item) {
            $old = $before[$item->id] ?? [];
            $added = array_values(array_diff($this->properties($item), $old));
            $removed = array_values(array_diff($old, $this->properties($item)));
            if ($added !== []) {
                $this->fixed('MANIFEST_PROPERTY_MISSING', "Added the manifest properties of \"{$item->id}\": " . implode(', ', $added) . '.', $item->path);
            }

            if ($removed !== []) {
                $this->fixed('MANIFEST_PROPERTY_UNNEEDED', "Removed the manifest properties of \"{$item->id}\": " . implode(', ', $removed) . '.', $item->path);
            }
        }
    }

    /**
     * One entry per linear XHTML document of the reading order, titled after its file.
     *
     * @return list<TocEntry>
     */
    private function readingOrderEntries(): array
    {
        $entries = [];
        foreach ($this->epub->getSpine()->getItems() as $spineItem) {
            $item = $spineItem->item;
            $isNav = $item instanceof ManifestItem && in_array('nav', $this->properties($item), true);
            if ($spineItem->linear && $item instanceof ManifestItem && ! $isNav && $item->path !== '' && $item->mediaType === self::XHTML_MEDIA_TYPE) {
                $entries[] = new TocEntry(pathinfo($item->path, PATHINFO_FILENAME), $item->path);
            }
        }

        return $entries;
    }

    private function navPath(): ?string
    {
        foreach ($this->epub->getManifest()->getItems() as $item) {
            if ($item->path !== '' && in_array('nav', $this->properties($item), true)) {
                return $item->path;
            }
        }

        return null;
    }

    private function ncxPath(): ?string
    {
        foreach ($this->epub->getManifest()->getItems() as $item) {
            if ($item->path !== '' && $item->mediaType === self::NCX_MEDIA_TYPE) {
                return $item->path;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function properties(ManifestItem $item): array
    {
        return preg_split('/\s+/', trim($item->properties), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function fileExists(string $root, string $path): bool
    {
        try {
            return is_file($this->paths->resolve($root, $path));
        } catch (Exception) {
            return false;
        }
    }

    /**
     * The image media type of a file's content (JPEG, PNG, GIF, WebP); null for other content.
     */
    private function sniffImage(string $root, string $path): ?string
    {
        try {
            $file = $this->paths->resolve($root, $path);
        } catch (Exception) {
            return null;
        }

        $prefix = is_file($file) ? @file_get_contents($file, false, null, 0, self::SNIFF_BYTES) : false;
        $size = $prefix === false ? false : @getimagesizefromstring($prefix);

        return $size !== false && in_array($size['mime'], self::IMAGE_TYPES, true) ? $size['mime'] : null;
    }
}
