<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\ConversionException;
use PhpEpub\Util\HtmlText;

/**
 * Exports a book as plain UTF-8 text: the book title and authors, then each chapter of the reading order under
 * a heading (the chapter's title in the table of contents, else its first heading), with a blank line between
 * paragraphs.
 *
 * The text is read from the same sanitised chapters the PDF adapters render (see EpubDocumentLoader), so it never
 * contains markup, script or style content.
 */
final readonly class TextAdapter implements ConverterInterface
{
    private const int MAX_RULE_LENGTH = 72;

    /**
     * @param bool $includeNonLinear Also export the auxiliary spine items (linear="no"), such as notes.
     * @param bool $headings Put a heading over each chapter and a title block over the book.
     */
    public function __construct(
        private bool $includeNonLinear = false,
        private bool $headings = true,
        private EpubDocumentLoader $loader = new EpubDocumentLoader()
    ) {
    }

    /**
     * Writes the text of the extracted book to $outputPath.
     *
     * @throws ConversionException If the book cannot be read, is DRM-protected, or the file cannot be written.
     */
    public function convert(string $epubDirectory, string $outputPath): void
    {
        $text = $this->toString($epubDirectory);

        if (@file_put_contents($outputPath, $text) === false) {
            throw new ConversionException("Failed to write the text file: {$outputPath}");
        }
    }

    /**
     * The text of the extracted book.
     *
     * @throws ConversionException If the book cannot be read or is DRM-protected.
     */
    public function toString(string $epubDirectory): string
    {
        $document = $this->loader->load($epubDirectory);

        $blocks = [];
        if ($this->headings && $document->title !== '') {
            $blocks[] = self::heading($document->title) . ($document->authors === [] ? '' : "\n\nby " . implode(', ', $document->authors));
        }

        $chapters = [];
        foreach ($document->chapters as $index => $html) {
            if (! $this->includeNonLinear && ! ($document->chapterLinear[$index] ?? true)) {
                continue;
            }

            $lines = self::lines($html);
            if ($lines === []) {
                continue;
            }

            $title = ($document->tocTitles[$index] ?? '') !== '' ? $document->tocTitles[$index] : ($document->chapterTitles[$index] ?? '');
            if ($this->headings && $title !== '') {
                // The chapter usually repeats its title as its first line; show it once, as the heading.
                if (mb_strtolower($lines[0]) === mb_strtolower($title)) {
                    array_shift($lines);
                }

                $chapters[] = self::heading($title) . ($lines === [] ? '' : "\n\n" . implode("\n\n", $lines));

                continue;
            }

            $chapters[] = implode("\n\n", $lines);
        }

        $text = implode("\n\n\n", [...$blocks, ...$chapters]);

        return $text === '' ? '' : $text . "\n";
    }

    /**
     * The paragraphs of a chapter, without the invisible anchors the loader adds for links between chapters.
     *
     * @return list<string>
     */
    private static function lines(string $html): array
    {
        $lines = [];
        foreach (explode("\n", str_replace("\u{200B}", '', HtmlText::extract($html))) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function heading(string $title): string
    {
        return $title . "\n" . str_repeat('=', min(mb_strlen($title), self::MAX_RULE_LENGTH));
    }
}
