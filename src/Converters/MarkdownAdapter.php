<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\ConversionException;
use PhpEpub\Util\HtmlSanitizer;
use PhpEpub\Util\HtmlToMarkdown;

/**
 * Exports a book as Markdown (CommonMark, with GFM tables): the title, then each chapter of the reading order.
 *
 * Headings, paragraphs, emphasis, links, lists, block quotes, code, images and simple tables are converted;
 * styling, scripts and everything else are dropped. The Markdown contains no HTML: text is escaped, links keep
 * only http, https and mailto URLs (links inside the book become plain text), and tables that GFM cannot express
 * become plain text. Images are returned as a map of path ("images/name.jpg") to bytes, with the Markdown
 * referring to them by those relative paths; images over the limits are replaced by their alt text.
 *
 * Use export() for the Markdown and the images in memory, MarkdownExport::writeTo() to write "book.md" and
 * "images/" into a directory, or convert() to write the Markdown file with its "images" folder beside it.
 */
final readonly class MarkdownAdapter implements ConverterInterface
{
    /**
     * @param bool $includeNonLinear Also export the auxiliary spine items (linear="no"), such as notes.
     * @param bool $includeHeader Start with the book title and authors.
     * @param int $maxImageBytes The most image data exported in total.
     * @param int $maxImageSize The largest single image exported.
     */
    public function __construct(
        private bool $includeNonLinear = false,
        private bool $includeHeader = true,
        private int $maxImageBytes = 64 * 1024 * 1024,
        private int $maxImageSize = 16 * 1024 * 1024,
        private EpubDocumentLoader $loader = new EpubDocumentLoader()
    ) {
    }

    /**
     * Writes the Markdown to $outputPath and the images into an "images" folder beside it.
     *
     * @throws ConversionException If the book cannot be read, is DRM-protected, or a file cannot be written.
     */
    public function convert(string $epubDirectory, string $outputPath): void
    {
        $export = $this->export($epubDirectory);
        $export->writeTo(dirname($outputPath), basename($outputPath));
    }

    /**
     * @throws ConversionException If the book cannot be read or is DRM-protected.
     */
    public function export(string $epubDirectory): MarkdownExport
    {
        $document = $this->loader->load($epubDirectory);
        $reader = new BookImages($document->directory);

        /** @var array<string, string> $files */
        $files = [];
        /** @var array<string, string|null> $paths */
        $paths = [];
        $total = 0;

        $imagePath = function (string $source) use ($reader, &$files, &$paths, &$total): ?string {
            if (array_key_exists($source, $paths)) {
                return $paths[$source];
            }

            $image = $reader->read($source, min($this->maxImageSize, $this->maxImageBytes - $total));
            if ($image === null) {
                return $paths[$source] = null;
            }

            $path = self::uniquePath($source, $image['extension'], $files);
            $files[$path] = $image['data'];
            $total += strlen($image['data']);

            return $paths[$source] = $path;
        };

        $sanitizer = new HtmlSanitizer($imagePath);
        $converter = new HtmlToMarkdown();

        $parts = [];
        if ($this->includeHeader && $document->title !== '') {
            $parts[] = '# ' . trim((string) preg_replace('/\s+/', ' ', $this->escape($document->title)))
                . ($document->authors === [] ? '' : "\n\n*" . $this->escape(implode(', ', $document->authors)) . '*');
        }

        foreach ($document->chapters as $index => $html) {
            if (! $this->includeNonLinear && ! ($document->chapterLinear[$index] ?? true)) {
                continue;
            }

            $body = HtmlSanitizer::parseBody($html);
            $sanitizer->sanitize($body);
            $markdown = trim($converter->convert($body));
            if ($markdown === '') {
                continue;
            }

            $title = ($document->tocTitles[$index] ?? '') !== '' ? $document->tocTitles[$index] : ($document->chapterTitles[$index] ?? '');
            $parts[] = $title !== '' && ! str_starts_with($markdown, '#') ? '# ' . $this->escape($title) . "\n\n" . $markdown : $markdown;
        }

        $text = implode("\n\n", $parts);

        return new MarkdownExport($text === '' ? '' : $text . "\n", $files);
    }

    private function escape(string $text): string
    {
        return (string) preg_replace('/[\\\\`*_\[\]<>&~|]/', '\\\\$0', $text);
    }

    /**
     * "images/<name>" for an image: the file name of its source (letters, digits, ".", "_" and "-"), or "image" for
     * a data: URI, with the detected extension and a number when the name is taken.
     *
     * @param array<string, string> $taken
     */
    private static function uniquePath(string $source, string $extension, array $taken): string
    {
        $name = str_starts_with(strtolower(ltrim($source)), 'data:') ? 'image' : pathinfo(str_replace('\\', '/', $source), PATHINFO_FILENAME);
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name), '._-');
        $name = $name === '' ? 'image' : substr($name, 0, 60);

        for ($number = 1;; $number++) {
            $path = 'images/' . $name . ($number === 1 ? '' : '-' . $number) . '.' . $extension;
            if (! isset($taken[$path])) {
                return $path;
            }
        }
    }
}
