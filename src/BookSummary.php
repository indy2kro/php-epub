<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\WordCount;

/**
 * A JSON-ready description of a loaded book, built by EpubFile::toArray(): metadata, cover, table of
 * contents, reading order, statistics and DRM state in one array of scalars and lists.
 *
 * The array holds only strings, ints, bools, nulls and arrays, so json_encode() accepts it. Dates are
 * the W3CDTF/ISO 8601 strings written in the book. Optional parts a book lacks are null or empty.
 * The table of contents is a tree: each node's "children" is a list of nodes of the same shape.
 *
 * @phpstan-type PersonShape array{name: string, role: string|null, fileAs: string|null}
 * @phpstan-type TocNodeShape array{title: string, path: string, fragment: string|null, children: list<array<string, mixed>>}
 * @phpstan-type SpineShape array{idref: string, path: string, linear: bool, title: string|null, bytes: int}
 * @phpstan-type GroupShape array{count: int, bytes: int}
 * @phpstan-type MetadataShape array{
 *     titles: list<string>,
 *     creators: list<PersonShape>,
 *     contributors: list<PersonShape>,
 *     subjects: list<string>,
 *     description: string|null,
 *     publisher: string|null,
 *     languages: list<string>,
 *     rights: list<string>,
 *     dates: array{published: string|null, modified: string|null, events: array<string, string>},
 *     identifiers: array{all: list<array{value: string, scheme: string|null}>, unique: string|null, isbn: string|null},
 *     series: array{name: string|null, index: string|null},
 *     accessibility: array{
 *         accessModes: list<string>,
 *         accessModesSufficient: list<string>,
 *         features: list<string>,
 *         hazards: list<string>,
 *         summary: string|null,
 *         conformsTo: string|null,
 *         certifiedBy: string|null
 *     },
 *     rendition: array{
 *         layout: string|null,
 *         orientation: string|null,
 *         spread: string|null,
 *         flow: string|null,
 *         pageProgressionDirection: string|null
 *     }
 * }
 * @phpstan-type StatsShape array{
 *     groups: array{xhtml: GroupShape, css: GroupShape, images: GroupShape, fonts: GroupShape, media: GroupShape, other: GroupShape},
 *     totalBytes: int,
 *     wordCount: int,
 *     readingMinutes: int
 * }
 * @phpstan-type SummaryShape array{
 *     version: string,
 *     metadata: MetadataShape,
 *     cover: array{path: string, mediaType: string, bytes: int}|null,
 *     toc: array{
 *         entries: list<TocNodeShape>,
 *         landmarks: list<array{type: string, title: string, path: string, fragment: string|null}>,
 *         pageListCount: int
 *     },
 *     spine: list<SpineShape>,
 *     stats: StatsShape,
 *     drm: array{isDrmProtected: bool, encryptedPathCount: int}
 * }
 */
final class BookSummary
{
    /**
     * Words per minute an adult reads at, for the reading time estimate.
     */
    public const int WORDS_PER_MINUTE = 230;

    private const array FONT_TYPES = [
        'application/vnd.ms-opentype', 'application/font-woff', 'application/font-woff2', 'application/font-sfnt',
        'application/x-font-ttf', 'application/x-font-truetype', 'application/x-font-opentype', 'application/x-font-woff',
    ];

    /**
     * @return SummaryShape
     *
     * @throws Exception If the book is not loaded.
     */
    public static function of(EpubFile $book): array
    {
        $toc = $book->getTableOfContents();
        $entries = self::safeList(static fn (): array => $toc->getEntries());
        $titles = [];
        self::collectTitles($entries, $titles);

        return [
            'version' => $book->getMetadata()->getVersion(),
            'metadata' => self::metadata($book),
            'cover' => self::cover($book),
            'toc' => [
                'entries' => self::tocNodes($entries),
                'landmarks' => array_map(
                    static fn (Landmark $landmark): array => [
                        'type' => $landmark->type,
                        'title' => $landmark->title,
                        'path' => self::utf8($landmark->path),
                        'fragment' => self::utf8Or($landmark->fragment),
                    ],
                    self::safeList(static fn (): array => $toc->getLandmarks())
                ),
                'pageListCount' => count(self::safeList(static fn (): array => $toc->getPageList())),
            ],
            'spine' => self::spine($book, $titles),
            'stats' => self::stats($book),
            'drm' => [
                'isDrmProtected' => $book->isDrmProtected(),
                'encryptedPathCount' => count($book->getEncryptedPaths()),
            ],
        ];
    }

    /**
     * Estimated reading time: the words at WORDS_PER_MINUTE, rounded up; 0 for no words.
     */
    public static function readingMinutes(int $words): int
    {
        return (int) ceil($words / self::WORDS_PER_MINUTE);
    }

    /**
     * @return MetadataShape
     */
    private static function metadata(EpubFile $book): array
    {
        $metadata = $book->getMetadata();
        $person = static fn (Contributor $contributor): array => [
            'name' => $contributor->name,
            'role' => $contributor->role,
            'fileAs' => $contributor->fileAs,
        ];

        return [
            'titles' => $metadata->getTitles(),
            'creators' => array_map($person, $metadata->getCreators()),
            'contributors' => array_map($person, $metadata->getContributors()),
            'subjects' => $metadata->getSubjects(),
            'description' => self::nonEmpty($metadata->getDescription()),
            'publisher' => self::nonEmpty($metadata->getPublisher()),
            'languages' => $metadata->getLanguages(),
            'rights' => array_values(array_filter(
                array_map(trim(...), $metadata->getDublinCoreValues('rights')),
                static fn (string $value): bool => $value !== ''
            )),
            'dates' => [
                'published' => self::nonEmpty($metadata->getDate()),
                'modified' => $metadata->getModifiedDate(),
                'events' => $metadata->getDateEvents(),
            ],
            'identifiers' => [
                'all' => array_map(
                    static fn (Identifier $identifier): array => ['value' => $identifier->value, 'scheme' => $identifier->scheme],
                    $metadata->getTypedIdentifiers()
                ),
                'unique' => $metadata->getUniqueIdentifier(),
                'isbn' => $metadata->getIsbn(),
            ],
            'series' => ['name' => $metadata->getSeries(), 'index' => $metadata->getSeriesIndex()],
            'accessibility' => [
                'accessModes' => $metadata->getAccessModes(),
                'accessModesSufficient' => $metadata->getAccessModesSufficient(),
                'features' => $metadata->getAccessibilityFeatures(),
                'hazards' => $metadata->getAccessibilityHazards(),
                'summary' => $metadata->getAccessibilitySummary(),
                'conformsTo' => $metadata->getConformsTo(),
                'certifiedBy' => $metadata->getCertifiedBy(),
            ],
            'rendition' => [
                'layout' => $metadata->getRenditionLayout(),
                'orientation' => $metadata->getRenditionOrientation(),
                'spread' => $metadata->getRenditionSpread(),
                'flow' => $metadata->getRenditionFlow(),
                'pageProgressionDirection' => $book->getSpine()->getPageProgressionDirection(),
            ],
        ];
    }

    /**
     * @return array{path: string, mediaType: string, bytes: int}|null
     */
    private static function cover(EpubFile $book): ?array
    {
        $cover = $book->getCoverImage();

        return $cover instanceof ManifestItem
            ? ['path' => self::utf8($cover->path), 'mediaType' => $cover->mediaType, 'bytes' => self::fileSize($book, $cover->path)]
            : null;
    }

    /**
     * @param list<TocEntry> $entries
     *
     * @return list<TocNodeShape>
     */
    private static function tocNodes(array $entries): array
    {
        return array_map(
            static fn (TocEntry $entry): array => [
                'title' => $entry->title,
                'path' => self::utf8($entry->path),
                'fragment' => self::utf8Or($entry->fragment),
                'children' => self::tocNodes($entry->children),
            ],
            $entries
        );
    }

    /**
     * Collects path => title of the first entry that targets each file, in document order.
     *
     * @param list<TocEntry> $entries
     * @param array<string, string> $titles
     */
    private static function collectTitles(array $entries, array &$titles): void
    {
        foreach ($entries as $entry) {
            if ($entry->path !== '') {
                $titles[$entry->path] ??= $entry->title;
            }

            self::collectTitles($entry->children, $titles);
        }
    }

    /**
     * @param array<string, string> $titles
     *
     * @return list<SpineShape>
     */
    private static function spine(EpubFile $book, array $titles): array
    {
        $spine = [];
        foreach ($book->getSpine()->getItems() as $spineItem) {
            $path = $spineItem->item instanceof ManifestItem ? $spineItem->item->path : '';
            $spine[] = [
                'idref' => $spineItem->idref,
                'path' => self::utf8($path),
                'linear' => $spineItem->linear,
                'title' => $titles[$path] ?? null,
                'bytes' => self::fileSize($book, $path),
            ];
        }

        return $spine;
    }

    /**
     * @return StatsShape
     */
    private static function stats(EpubFile $book): array
    {
        $groups = [
            'xhtml' => ['count' => 0, 'bytes' => 0],
            'css' => ['count' => 0, 'bytes' => 0],
            'images' => ['count' => 0, 'bytes' => 0],
            'fonts' => ['count' => 0, 'bytes' => 0],
            'media' => ['count' => 0, 'bytes' => 0],
            'other' => ['count' => 0, 'bytes' => 0],
        ];
        foreach ($book->getManifest()->getItems() as $item) {
            $group = self::group($item->mediaType);
            $groups[$group]['count']++;
            $groups[$group]['bytes'] += self::fileSize($book, $item->path);
        }

        $words = self::wordCount($book);

        return [
            'groups' => $groups,
            'totalBytes' => self::totalBytes($book),
            'wordCount' => $words,
            'readingMinutes' => self::readingMinutes($words),
        ];
    }

    /**
     * @return 'xhtml'|'css'|'images'|'fonts'|'media'|'other'
     */
    private static function group(string $mediaType): string
    {
        $type = strtolower(trim(explode(';', $mediaType)[0]));

        return match (true) {
            in_array($type, ['application/xhtml+xml', 'text/html'], true) => 'xhtml',
            $type === 'text/css' => 'css',
            str_starts_with($type, 'image/') => 'images',
            str_starts_with($type, 'font/'), in_array($type, self::FONT_TYPES, true) => 'fonts',
            str_starts_with($type, 'audio/'), str_starts_with($type, 'video/') => 'media',
            default => 'other',
        };
    }

    /**
     * The words of the linear content, document by document so only one text is held at a time. Encrypted
     * documents are skipped without being read (their bytes are not text), as are unreadable ones.
     */
    private static function wordCount(EpubFile $book): int
    {
        $encrypted = array_flip($book->getEncryptedPaths());
        $contentManager = $book->getContentManager();
        $existing = array_flip($contentManager->getContentPaths());

        $words = 0;
        foreach ($book->getSpine()->getItems() as $spineItem) {
            $item = $spineItem->item;
            if (! $spineItem->linear || ! $item instanceof ManifestItem || self::group($item->mediaType) !== 'xhtml') {
                continue;
            }

            if (isset($encrypted[$item->path]) || ! isset($existing[$item->path])) {
                continue;
            }

            try {
                $words += WordCount::count($contentManager->getText($item->path));
            } catch (Exception) {
                // An unreadable document adds nothing; the others are still counted.
            }
        }

        return $words;
    }

    private static function totalBytes(EpubFile $book): int
    {
        $root = $book->getTempDir();

        return $root !== null && is_dir($root) ? self::directoryBytes($root) : 0;
    }

    /**
     * The size of the files below a directory; symbolic links are not followed.
     */
    private static function directoryBytes(string $directory): int
    {
        $total = 0;
        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if ($name === '.' || $name === '..' || is_link($path)) {
                continue;
            }

            $total += is_dir($path) ? self::directoryBytes($path) : (int) filesize($path);
        }

        return $total;
    }

    private static function fileSize(EpubFile $book, string $path): int
    {
        $root = $book->getTempDir();
        if ($root === null || $path === '') {
            return 0;
        }

        $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $size = is_file($file) && ! is_link($file) ? filesize($file) : false;

        return $size === false ? 0 : $size;
    }

    /**
     * @template T
     *
     * @param callable(): list<T> $read
     *
     * @return list<T>
     */
    private static function safeList(callable $read): array
    {
        try {
            return $read();
        } catch (Exception) {
            // A navigation document or NCX that cannot be parsed counts as missing; validate() reports it.
            return [];
        }
    }

    /**
     * Paths and fragments are percent-decoded from hrefs, so they can hold bytes that are not UTF-8, which
     * json_encode() refuses: invalid sequences become "?".
     */
    private static function utf8(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
    }

    private static function utf8Or(?string $value): ?string
    {
        return $value === null ? null : self::utf8($value);
    }

    private static function nonEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
