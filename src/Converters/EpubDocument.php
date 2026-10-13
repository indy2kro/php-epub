<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\TocEntry;

/**
 * The renderable content of a book, as prepared by EpubDocumentLoader.
 */
final readonly class EpubDocument
{
    /**
     * @param string $title The book title ("" when unknown).
     * @param list<string> $authors The book authors.
     * @param list<string> $chapters The <body> HTML of each spine document, in reading order.
     * @param list<string> $styles The book's CSS (linked stylesheets and <style> blocks, each once), with
     *                             resources confined to the book.
     * @param list<string> $chapterTitles A title per chapter (its first h1-h3 heading, else its <title>; "" when
     *                                    it has neither), in the same order as $chapters.
     * @param string $directory The book's directory (real path); renderers may read files only inside it.
     * @param string $coverImage The cover image (absolute path, inside the book) to show as the first page,
     *                           because no chapter shows it; "" when there is none.
     * @param string $language The main language of the book (its first dc:language; "" when unknown).
     * @param bool $rightToLeft Whether the book reads right to left: its spine says so
     *                          (page-progression-direction="rtl"), or, when the spine does not say,
     *                          its main language is written right to left.
     * @param string $contents The HTML of a generated contents page (see PdfConversionOptions::$includeToc);
     *                         "" when there is none.
     * @param list<string> $chapterPaths The book-relative path of each chapter, in the same order as $chapters.
     * @param list<bool> $chapterLinear Whether each chapter is part of the primary reading order (false for
     *                                  spine items marked linear="no"), in the same order as $chapters.
     * @param list<string> $tocTitles The title the book's table of contents gives each chapter ("" when it
     *                                lists none), in the same order as $chapters.
     * @param list<TocEntry> $outline The entries of the book's table of contents, nested (empty when the book has
     *                                none or it cannot be read).
     */
    public function __construct(
        public string $title,
        public array $authors,
        public array $chapters,
        public array $styles = [],
        public array $chapterTitles = [],
        public string $directory = '',
        public string $coverImage = '',
        public string $language = '',
        public bool $rightToLeft = false,
        public string $contents = '',
        public array $chapterPaths = [],
        public array $chapterLinear = [],
        public array $tocTitles = [],
        public array $outline = []
    ) {
    }

    /**
     * The table of contents as outline entries per chapter: chapter index => list of [title, depth], in
     * reading order (depth 0 is the top level). An entry without a target in the book (a heading of a group)
     * is attached to the chapter of the next entry that has one, so it still precedes its children; trailing
     * ones are dropped. Chapters the table of contents does not list have no key.
     *
     * @return array<int, list<array{string, int}>>
     */
    public function outlineByChapter(): array
    {
        $byChapter = [];
        $pending = [];
        self::collectOutline($this->outline, 0, array_flip($this->chapterPaths), $byChapter, $pending);

        return $byChapter;
    }

    /**
     * @param list<TocEntry> $entries
     * @param array<string, int> $indexes Book-relative path => chapter index.
     * @param array<int, list<array{string, int}>> $byChapter
     * @param list<array{string, int}> $pending
     */
    private static function collectOutline(array $entries, int $depth, array $indexes, array &$byChapter, array &$pending): void
    {
        foreach ($entries as $entry) {
            $title = trim((string) preg_replace('/\s+/u', ' ', $entry->title));
            $chapter = $entry->path === '' ? null : ($indexes[$entry->path] ?? null);
            if ($title !== '') {
                if ($chapter === null) {
                    $pending[] = [$title, $depth];
                } else {
                    foreach ([...$pending, [$title, $depth]] as $item) {
                        $byChapter[$chapter][] = $item;
                    }
                    $pending = [];
                }
            }

            self::collectOutline($entry->children, $depth + 1, $indexes, $byChapter, $pending);
        }
    }

    /**
     * Whether text in this language runs right to left (Arabic, Hebrew, Persian, Urdu, Yiddish,
     * Pashto, Sindhi, Uyghur and Divehi), judged by the primary language subtag.
     */
    public static function isRightToLeftLanguage(string $language): bool
    {
        $primary = strtolower((string) strtok(str_replace('_', '-', trim($language)), '-'));

        return in_array($primary, ['ar', 'he', 'fa', 'ur', 'yi', 'ps', 'sd', 'ug', 'dv'], true);
    }

    /**
     * The styles joined for a <style> element; "</style" is broken up so CSS cannot end the element.
     *
     * @param list<string> $styles
     */
    public static function styleSheet(array $styles): string
    {
        return (string) preg_replace('#</(style)#i', '<\\/$1', implode("\n", $styles));
    }
}
