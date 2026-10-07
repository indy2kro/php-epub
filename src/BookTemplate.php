<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * The documents EpubFile writes when it creates a book or a chapter.
 *
 * @internal
 */
final class BookTemplate
{
    public static function container(string $opfPath): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">'
            . '<rootfiles><rootfile full-path="' . self::escape($opfPath) . '" media-type="application/oebps-package+xml"/></rootfiles>'
            . "</container>\n";
    }

    /**
     * An EPUB 3 package with the required metadata, a navigation document and an empty spine.
     */
    public static function package(string $title, string $language, string $identifier, string $navHref): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id" xml:lang="' . self::escape($language) . '">' . "\n"
            . '  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n"
            . '    <dc:identifier id="pub-id">' . self::escape($identifier) . "</dc:identifier>\n"
            . '    <dc:title>' . self::escape($title) . "</dc:title>\n"
            . '    <dc:language>' . self::escape($language) . "</dc:language>\n"
            . '    <meta property="dcterms:modified">' . gmdate('Y-m-d\TH:i:s\Z') . "</meta>\n"
            . "  </metadata>\n"
            . '  <manifest>' . "\n"
            . '    <item id="nav" href="' . self::escape($navHref) . '" media-type="application/xhtml+xml" properties="nav"/>' . "\n"
            . "  </manifest>\n"
            . "  <spine/>\n"
            . "</package>\n";
    }

    /**
     * An EPUB 3 navigation document with an empty table of contents.
     */
    public static function navigation(string $title, string $language): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="' . self::escape($language) . '">'
            . '<head><title>' . self::escape($title) . '</title></head>'
            . '<body><nav epub:type="toc" id="toc"><h1>Contents</h1><ol/></nav></body>'
            . "</html>\n";
    }

    /**
     * An XHTML content document. It has no HTML5 doctype or <meta charset>, so it is valid in EPUB 2 and EPUB 3.
     *
     * @param string $body The body markup, inserted as it is.
     */
    public static function chapter(string $title, string $language, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="' . self::escape($language) . '">'
            . '<head><title>' . self::escape($title) . '</title></head>'
            . '<body>' . $body . '</body>'
            . "</html>\n";
    }

    /**
     * A random (version 4) UUID URN, e.g. "urn:uuid:3f1e2a4c-5b6d-4e7f-8a9b-0c1d2e3f4a5b".
     */
    public static function uuidUrn(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return 'urn:uuid:' . vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
