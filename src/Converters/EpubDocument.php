<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

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
     */
    public function __construct(
        public string $title,
        public array $authors,
        public array $chapters,
        public array $styles = [],
        public array $chapterTitles = []
    ) {
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
