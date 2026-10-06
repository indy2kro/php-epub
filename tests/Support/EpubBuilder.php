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

    public function withFile(string $path, string $content): self
    {
        $this->files[$path] = $content;

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
