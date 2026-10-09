<?php

declare(strict_types=1);

namespace PhpEpub\Test\Support;

use PhpEpub\EpubFile;

/**
 * Small EPUB 3 books for the cleanup tests: a package under EPUB/ with the given manifest items,
 * spine and files, opened from a scratch archive.
 */
final class CleanupBook
{
    /**
     * @param string $items The <item> elements after the always present nav and chapter.
     * @param array<string, string> $files Files to add, by book path (the chapter is EPUB/chapter.xhtml).
     * @param string $spine The itemrefs after the chapter.
     * @param string $chapterBody The chapter's body markup.
     */
    public static function builder(string $items = '', array $files = [], string $spine = '', string $chapterBody = '<p>Text.</p>', string $metadata = ''): EpubBuilder
    {
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:00000000-0000-0000-0000-000000000000</dc:identifier>
    <dc:title>Cleanup</dc:title>
    <dc:language>en</dc:language>
    <meta property="dcterms:modified">2026-01-01T00:00:00Z</meta>
    {$metadata}
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/>
    {$items}
  </manifest>
  <spine>
    <itemref idref="chapter"/>
    {$spine}
  </spine>
</package>
XML;
        $book = (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', EpubBuilder::xhtml('Contents', '<nav epub:type="toc"><ol><li><a href="chapter.xhtml">Chapter</a></li></ol></nav>'))
            ->withFile('EPUB/chapter.xhtml', EpubBuilder::xhtml('Chapter', $chapterBody));

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
