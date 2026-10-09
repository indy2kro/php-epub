<?php

declare(strict_types=1);

namespace PhpEpub\Repair;

/**
 * A kind of repair Repairer can apply; RepairOptions switches them on and off individually.
 */
enum RepairFix: string
{
    /** Renames manifest items whose id an earlier item already uses (DUPLICATE_ID). */
    case DuplicateIds = 'duplicate-ids';

    /** Removes manifest items without a file from the manifest and the spine (MANIFEST_FILE_MISSING). */
    case MissingFiles = 'missing-files';

    /** Removes spine itemrefs that are unknown to the manifest or repeated (SPINE_UNKNOWN_IDREF, SPINE_DUPLICATE_IDREF). */
    case SpineReferences = 'spine-references';

    /** Adds files of the book that the manifest does not list (FILE_NOT_IN_MANIFEST). */
    case UnlistedFiles = 'unlisted-files';

    /** Corrects media types that do not match the file (MEDIA_TYPE_MISMATCH). */
    case MediaTypes = 'media-types';

    /** Declares and flags the cover image (EPUB 3 cover-image property, EPUB 2 cover meta; COVER_NOT_IMAGE). */
    case Cover = 'cover';

    /** Converts an EPUB 2 book to EPUB 3. Not part of the default fixes. */
    case UpgradeToEpub3 = 'upgrade-to-epub3';

    /** Sets a missing dcterms:modified (METADATA_MODIFIED_MISSING). */
    case ModifiedDate = 'modified-date';

    /** Sets a missing dc:language (METADATA_LANGUAGE_MISSING). */
    case Language = 'language';

    /** Gives the package a unique identifier (METADATA_IDENTIFIER_MISSING, METADATA_UNIQUE_IDENTIFIER). */
    case Identifier = 'identifier';

    /** Creates a missing or empty navigation document or NCX (NAV_MISSING, NCX_MISSING, NAV_EMPTY, NCX_EMPTY). */
    case Navigation = 'navigation';

    /** Drops table-of-contents entries that link to files that are not in the book (TOC_LINK_NOT_IN_MANIFEST). */
    case TocLinks = 'toc-links';

    /** Recomputes the nav, scripted, svg, remote-resources and mathml manifest properties (MANIFEST_PROPERTY_MISSING, MANIFEST_PROPERTY_UNNEEDED). */
    case ManifestProperties = 'manifest-properties';

    /**
     * Every fix except UpgradeToEpub3, which changes the book's version.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $fix): bool => $fix !== self::UpgradeToEpub3));
    }
}
