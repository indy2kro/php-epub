<?php

declare(strict_types=1);

namespace PhpEpub\Build;

use DateTimeInterface;

/**
 * The metadata and settings of a book built by BookBuilder.
 */
final readonly class BookOptions
{
    /**
     * @param string $title The book title.
     * @param list<string> $authors The authors.
     * @param string $language The language, as a BCP 47 tag ("en", "pt-BR").
     * @param string|null $identifier The unique identifier; a random "urn:uuid:..." when null.
     * @param string $description A description ("" for none).
     * @param string $publisher The publisher ("" for none).
     * @param string|DateTimeInterface|null $date The publication date: "YYYY", "YYYY-MM", "YYYY-MM-DD" or anything
     *                                            DateTimeImmutable reads (written as YYYY-MM-DD); none when null.
     * @param string|null $coverImage The cover image's bytes (JPEG, PNG, GIF or WebP); fromImages() uses its first
     *                                image instead.
     * @param string $css Extra CSS for the chapters, after a small default. Every url() and @import is removed.
     * @param array<string, string> $images Images the Markdown or HTML refers to: the path as it is written there
     *                                      (or just the file name) => the image's bytes. Images that are not in this
     *                                      map are never fetched; they are left out and their alt text stays.
     * @param int $splitLevel Chapters start at headings up to this level: 1 for h1 only, 2 for h1 and h2, up to 6.
     * @param string $chapterPattern A regular expression, matched against each trimmed line of a text, that marks a
     *                               chapter heading (fromText() only).
     * @param string $direction The reading direction of the pages: "ltr" or "rtl" (right-to-left manga, Arabic, Hebrew).
     * @param BuildLimits $limits Limits for untrusted input.
     */
    public function __construct(
        public string $title = 'Untitled',
        public array $authors = [],
        public string $language = 'en',
        public ?string $identifier = null,
        public string $description = '',
        public string $publisher = '',
        public string|DateTimeInterface|null $date = null,
        public ?string $coverImage = null,
        public string $css = '',
        public array $images = [],
        public int $splitLevel = 1,
        public string $chapterPattern = '/^(Chapter|CHAPTER)\s+\w+/',
        public string $direction = 'ltr',
        public BuildLimits $limits = new BuildLimits()
    ) {
    }
}
