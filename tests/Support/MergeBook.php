<?php

declare(strict_types=1);

namespace PhpEpub\Test\Support;

use PhpEpub\EpubFile;

/**
 * Small EPUB 3 books for the merge and split tests: chapters EPUB/text/chapter-N.xhtml that share the
 * stylesheet EPUB/css/style.css, a navigation document with one entry per chapter, and optional extra files.
 */
final class MergeBook
{
    /**
     * @param string $items Extra <item> elements.
     * @param array<string, string> $files Extra files by book path (or replacements of the generated ones).
     * @param array<int, string> $chapterBodies Bodies by chapter number (1-based); the others get a paragraph.
     */
    public static function builder(
        string $uid,
        string $title,
        int $chapters = 2,
        string $items = '',
        array $files = [],
        string $css = 'p { margin: 0; }',
        array $chapterBodies = [],
        string $metadata = '',
        string $spineAttributes = ''
    ): EpubBuilder {
        $manifest = '';
        $spine = '';
        $nav = '';
        for ($number = 1; $number <= $chapters; $number++) {
            $manifest .= "<item id=\"chapter{$number}\" href=\"text/chapter-{$number}.xhtml\" media-type=\"application/xhtml+xml\"/>\n";
            $spine .= "<itemref idref=\"chapter{$number}\"/>\n";
            $nav .= "<li><a href=\"text/chapter-{$number}.xhtml\">{$title} chapter {$number}</a></li>";
        }

        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">{$uid}</dc:identifier>
    <dc:title>{$title}</dc:title>
    <dc:creator>Author of {$title}</dc:creator>
    <dc:language>en</dc:language>
    <meta property="dcterms:modified">2026-01-01T00:00:00Z</meta>
    <meta property="schema:accessMode">textual</meta>
    <meta property="schema:accessibilityFeature">tableOfContents</meta>
    <meta property="schema:accessibilityHazard">none</meta>
    <meta property="schema:accessibilitySummary">Plain text with a table of contents.</meta>
    {$metadata}
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="style" href="css/style.css" media-type="text/css"/>
    {$manifest}
    {$items}
  </manifest>
  <spine{$spineAttributes}>
    {$spine}
  </spine>
</package>
XML;

        $book = (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml('Contents', "<nav epub:type=\"toc\"><h1>Contents</h1><ol>{$nav}</ol></nav>"))
            ->withFile('EPUB/css/style.css', $css);

        for ($number = 1; $number <= $chapters; $number++) {
            $body = $chapterBodies[$number] ?? "<h1>{$title} {$number}</h1><p>Text of chapter {$number}.</p>";
            $book->withFile("EPUB/text/chapter-{$number}.xhtml", EpubBuilder::xhtml("{$title} {$number}", $body, '../css/style.css'));
        }

        foreach ($files as $path => $content) {
            $book->withFile($path, $content);
        }

        return $book;
    }

    public static function open(EpubBuilder $builder, string $directory): EpubFile
    {
        return EpubFile::open($builder->buildEpub($directory . DIRECTORY_SEPARATOR . 'book-' . bin2hex(random_bytes(4)) . '.epub'));
    }
}
