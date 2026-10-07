<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * One landmark of the book: a well-known place such as the cover, the table of contents or the
 * start of the body, as listed in the EPUB 3 navigation document's "landmarks" nav and in the
 * EPUB 2 <guide>.
 */
final readonly class Landmark
{
    /**
     * EPUB 2 guide reference types and the EPUB 3 structural semantics (epub:type) that match them.
     */
    private const array GUIDE_TO_EPUB3 = [
        'cover' => 'cover',
        'title-page' => 'titlepage',
        'toc' => 'toc',
        'index' => 'index',
        'glossary' => 'glossary',
        'acknowledgements' => 'acknowledgments',
        'bibliography' => 'bibliography',
        'colophon' => 'colophon',
        'copyright-page' => 'copyright-page',
        'dedication' => 'dedication',
        'epigraph' => 'epigraph',
        'foreword' => 'foreword',
        'loi' => 'loi',
        'lot' => 'lot',
        'notes' => 'endnotes',
        'preface' => 'preface',
        'text' => 'bodymatter',
    ];

    /**
     * @param string $type The EPUB 3 epub:type, e.g. "cover", "titlepage", "toc", "bodymatter".
     * @param string $title The text shown for the landmark.
     * @param string $path The target file relative to the book root, e.g. "EPUB/text/ch1.xhtml".
     * @param string|null $fragment The target anchor inside the file (without "#"), or null.
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $path,
        public ?string $fragment = null
    ) {
    }

    /**
     * The EPUB 3 type of an EPUB 2 guide reference type ("text" is "bodymatter"); null for a type
     * without an EPUB 3 equivalent, such as "other.unknown".
     */
    public static function fromGuideType(string $guideType): ?string
    {
        return self::GUIDE_TO_EPUB3[strtolower($guideType)] ?? null;
    }

    /**
     * The EPUB 2 guide reference type of an EPUB 3 type ("bodymatter" is "text"); null for a type
     * the guide cannot express.
     */
    public static function toGuideType(string $type): ?string
    {
        $guideType = array_search($type, self::GUIDE_TO_EPUB3, true);

        return $guideType === false ? null : (string) $guideType;
    }
}
