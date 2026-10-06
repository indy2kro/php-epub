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
     */
    public function __construct(
        public string $title,
        public array $authors,
        public array $chapters
    ) {
    }
}
