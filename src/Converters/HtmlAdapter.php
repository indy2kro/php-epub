<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use DOMElement;
use PhpEpub\ConversionException;
use PhpEpub\Util\CssSanitizer;
use PhpEpub\Util\CssScope;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\HtmlSanitizer;

/**
 * Exports a book as one self-contained, sanitised HTML file.
 *
 * - The chapters of the reading order follow each other, each in its own <section>, after a table of contents.
 * - The book's CSS is concatenated, with every selector scoped to the book's container, so it cannot restyle
 *   the page around it; "body" and "html" rules style the container.
 * - Images and fonts the book's files provide are inlined as data: URIs, up to a budget; images past it are
 *   replaced by their alt text.
 * - Links between chapters (and to footnotes) point at anchors in the file; a link whose target is not in the
 *   file loses its href.
 *
 * The output is safe to show on a page: only text, table and image elements and a few attributes remain (see
 * Util\HtmlSanitizer); there is no script, event handler, form, embedded document or media; links keep only
 * http, https, mailto and in-document targets; nothing loads from a remote URL (images, stylesheets, @import,
 * url(), srcset); and a Content-Security-Policy <meta> repeats that for browsers.
 */
final readonly class HtmlAdapter implements ConverterInterface
{
    private const string CONTAINER_CLASS = 'epub-book';

    private const string CONTENT_SECURITY_POLICY = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'";

    private const string BASE_CSS = '.epub-book{contain:layout paint;isolation:isolate;position:relative;overflow:hidden;max-width:48em;margin:0 auto;padding:1em;line-height:1.5;overflow-wrap:break-word}'
        . '.epub-book img{max-width:100%;height:auto}.epub-book .epub-chapter{margin:0 0 3em}'
        . '.epub-book table{border-collapse:collapse}.epub-book td,.epub-book th{border:1px solid #8885;padding:.2em .5em}';

    /**
     * On a wrapper the book's CSS cannot reach (its rules are scoped to the container inside): the book is clipped to
     * its box, stays below the page's own layers, and is the containing block of anything it positions.
     */
    private const string HOST_STYLE = 'contain:layout paint;isolation:isolate;position:relative;overflow:hidden';

    private const int MAX_FONT_BYTES = 8 * 1024 * 1024;

    /**
     * @param int $maxInlinedBytes The most data: URI bytes (images, fonts) in the output; images past it become their alt text.
     * @param bool $includeNonLinear Also export the auxiliary spine items (linear="no"), so links to notes keep working.
     * @param bool $includeToc Start with a table of contents when the book has several chapters.
     */
    public function __construct(
        private int $maxInlinedBytes = 32 * 1024 * 1024,
        private bool $includeNonLinear = true,
        private bool $includeToc = true,
        private EpubDocumentLoader $loader = new EpubDocumentLoader()
    ) {
    }

    /**
     * Writes the HTML of the extracted book to $outputPath.
     *
     * @throws ConversionException If the book cannot be read, is DRM-protected, or the file cannot be written.
     */
    public function convert(string $epubDirectory, string $outputPath): void
    {
        $html = $this->toString($epubDirectory);

        if (@file_put_contents($outputPath, $html) === false) {
            throw new ConversionException("Failed to write the HTML file: {$outputPath}");
        }
    }

    /**
     * The HTML document of the extracted book.
     *
     * @throws ConversionException If the book cannot be read or is DRM-protected.
     */
    public function toString(string $epubDirectory): string
    {
        $document = $this->loader->load($epubDirectory);
        $images = new BookImages($document->directory);
        $budget = $this->maxInlinedBytes;

        $inlineImage = static function (string $source) use ($images, &$budget): ?string {
            $image = $images->read($source, intdiv(max(0, $budget) * 3, 4));
            if ($image === null) {
                return null;
            }

            $uri = 'data:' . $image['mime'] . ';base64,' . base64_encode($image['data']);
            $budget -= strlen($uri);

            return $uri;
        };

        $cssUrl = static function (string $url) use ($images, $inlineImage, &$budget): ?string {
            // A font: the book's de-obfuscated fonts arrive as data: URIs, other fonts as files of the book.
            $isFontUri = preg_match('#^data:(?:font/|application/(?:x-)?font)#i', $url) === 1;
            $font = $isFontUri ? $url : $images->fontUri($url, min(self::MAX_FONT_BYTES, intdiv(max(0, $budget) * 3, 4)));
            if ($font !== null && strlen($font) <= max(0, $budget)) {
                $budget -= strlen($font);

                return $font;
            }

            return $isFontUri ? null : $inlineImage($url);
        };

        $sanitizer = new HtmlSanitizer($inlineImage, static fn (string $style): string => CssSanitizer::sanitize($style, $cssUrl));

        $styles = [];
        $chapters = [];
        $included = [];
        foreach ($document->chapters as $index => $html) {
            if (! $this->includeNonLinear && ! ($document->chapterLinear[$index] ?? true)) {
                continue;
            }

            $chapter = HtmlSanitizer::parseBody($html);
            foreach (iterator_to_array($chapter->getElementsByTagName('style')) as $style) {
                $styles[] = $style->textContent;
            }

            $sanitizer->sanitize($chapter);
            $chapters[$index] = $chapter;
            $included[] = $index;
        }

        $ids = [];
        foreach ($chapters as $chapter) {
            self::tidyAnchors($chapter, $ids);
        }

        $sections = [];
        foreach ($chapters as $index => $chapter) {
            self::dropDanglingLinks($chapter, $ids);
            $sections[$index] = '<section class="epub-chapter">' . self::serialize($chapter) . '</section>';
        }

        $css = self::BASE_CSS;
        $sheets = [];
        foreach ([...$document->styles, ...$styles] as $sheet) {
            $sheets[] = CssSanitizer::sanitize($sheet, $cssUrl);
        }

        $css .= "\n" . CssScope::scopeAll($sheets, '.' . self::CONTAINER_CLASS);

        return $this->page($document, $included, $sections, $css);
    }

    /**
     * @param list<int> $included Indexes of the exported chapters.
     * @param array<int, string> $sections
     */
    private function page(EpubDocument $document, array $included, array $sections, string $css): string
    {
        $title = htmlspecialchars($document->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $language = preg_match('/^[A-Za-z0-9-]{1,35}$/', $document->language) === 1 ? ' lang="' . $document->language . '"' : '';
        $direction = $document->rightToLeft ? ' dir="rtl"' : '';

        $head = '<meta charset="utf-8">'
            . '<meta http-equiv="Content-Security-Policy" content="' . self::CONTENT_SECURITY_POLICY . '">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title>'
            . ($document->authors === [] ? '' : '<meta name="author" content="' . htmlspecialchars(implode(', ', $document->authors), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">')
            . '<style>' . EpubDocument::styleSheet([$css]) . '</style>';

        $body = ($document->title === '' ? '' : '<header class="epub-title"><h1>' . $title . '</h1>'
            . ($document->authors === [] ? '' : '<p>' . htmlspecialchars(implode(', ', $document->authors), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>')
            . '</header>')
            . ($this->includeToc ? $this->toc($document, $included) : '')
            . implode("\n", $sections);

        return "<!DOCTYPE html>\n<html{$language}{$direction}><head>{$head}</head>"
            . '<body><div class="epub-host" style="' . self::HOST_STYLE . '"><div class="' . self::CONTAINER_CLASS . '"' . $direction . '>' . $body . "</div></div></body></html>\n";
    }

    /**
     * @param list<int> $included
     */
    private function toc(EpubDocument $document, array $included): string
    {
        $items = '';
        foreach ($included as $index) {
            $title = ($document->tocTitles[$index] ?? '') !== '' ? $document->tocTitles[$index] : ($document->chapterTitles[$index] ?? '');
            if ($title !== '') {
                $items .= '<li><a href="#epub-c' . $index . '">' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
            }
        }

        return count($included) > 1 && $items !== '' ? '<nav class="epub-toc"><h2>Contents</h2><ol>' . $items . '</ol></nav>' : '';
    }

    private static function serialize(DOMElement $body): string
    {
        $document = $body->ownerDocument;
        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= (string) $document?->saveHTML($child);
        }

        return $html;
    }

    /**
     * Records every id (HtmlSanitizer has already emptied the loader's link anchors, which hold a zero-width space).
     *
     * @param array<string, true> $ids
     */
    private static function tidyAnchors(DOMElement $body, array &$ids): void
    {
        foreach ($body->getElementsByTagName('*') as $element) {
            $id = $element->getAttribute('id');
            if ($id === '') {
                continue;
            }

            $ids[$id] = true;
        }
    }

    /**
     * @param array<string, true> $ids
     */
    private static function dropDanglingLinks(DOMElement $body, array $ids): void
    {
        foreach ($body->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            if (str_starts_with($href, '#') && ! isset($ids[rawurldecode(substr($href, 1))]) && ! isset($ids[substr($href, 1)])) {
                $link->removeAttribute('href');
            }
        }
    }
}
