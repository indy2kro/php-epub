<?php

declare(strict_types=1);

namespace PhpEpub\Test\Support;

use ZipArchive;

/**
 * Builds small EPUBs (extracted or zipped) inside tests, so hostile or
 * malformed books do not have to be committed as binary fixtures.
 */
final class EpubBuilder
{
    /**
     * @var array<string, string> path inside the book => content
     */
    private array $files = [];

    /**
     * A 1x1 PNG, for tests that need real image bytes (validators check them).
     */
    public const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * A small EPUB 3 book that passes EPUBCheck: navigation document, dcterms:modified
     * and complete XHTML documents.
     */
    public static function epub3(): self
    {
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:3f1e2a4c-5b6d-4e7f-8a9b-0c1d2e3f4a5b</dc:identifier>
    <dc:title>Valid Book</dc:title>
    <dc:language>en</dc:language>
    <meta property="dcterms:modified">2026-01-01T00:00:00Z</meta>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="chapter" href="text/chapter.xhtml" media-type="application/xhtml+xml"/>
    <item id="style" href="css/style.css" media-type="text/css"/>
  </manifest>
  <spine>
    <itemref idref="chapter"/>
  </spine>
</package>
XML;

        return (new self())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', self::xhtml(
                'Contents',
                '<nav epub:type="toc"><h1>Contents</h1><ol><li><a href="text/chapter.xhtml">Chapter</a></li></ol></nav>'
            ))
            ->withFile('EPUB/text/chapter.xhtml', self::xhtml('Chapter', '<h1>Chapter</h1><p>Text.</p>', '../css/style.css'))
            ->withFile('EPUB/css/style.css', 'p { margin: 0; }');
    }

    /**
     * A complete EPUB 3 XHTML content document.
     */
    public static function xhtml(string $title, string $body, ?string $stylesheet = null): string
    {
        $link = $stylesheet === null ? '' : "<link rel=\"stylesheet\" type=\"text/css\" href=\"{$stylesheet}\"/>";

        return '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html>'
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="en" lang="en">'
            . "<head><meta charset=\"utf-8\"/><title>{$title}</title>{$link}</head><body>{$body}</body></html>";
    }

    public static function minimal(): self
    {
        return (new self())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', self::opf())
            ->withFile('EPUB/chapter.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body><p>Chapter</p></body></html>');
    }

    public static function opf(string $manifestItems = '', string $metadata = ''): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:00000000-0000-0000-0000-000000000000</dc:identifier>
    <dc:title>Minimal</dc:title>
    <dc:language>en</dc:language>
    {$metadata}
  </metadata>
  <manifest>
    <item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/>
    {$manifestItems}
  </manifest>
  <spine>
    <itemref idref="chapter"/>
  </spine>
</package>
XML;
    }

    public function withContainer(string $fullPath): self
    {
        return $this->withFile('META-INF/container.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">
  <rootfiles>
    <rootfile full-path="{$fullPath}" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
XML);
    }

    public function getFile(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function withFile(string $path, string $content): self
    {
        $this->files[$path] = $content;

        return $this;
    }

    public function withoutFile(string $path): self
    {
        unset($this->files[$path]);

        return $this;
    }

    /**
     * Writes the book as an extracted directory and returns that directory.
     */
    public function writeTo(string $directory): string
    {
        foreach ($this->files as $path => $content) {
            $target = $directory . '/' . $path;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            file_put_contents($target, $content);
        }

        return $directory;
    }

    /**
     * Writes the book as a .epub archive and returns its path.
     */
    public function buildEpub(string $epubPath): string
    {
        $zip = new ZipArchive();
        $zip->open($epubPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($this->files as $path => $content) {
            $zip->addFromString($path, $content);
        }
        $zip->close();

        return $epubPath;
    }
}
