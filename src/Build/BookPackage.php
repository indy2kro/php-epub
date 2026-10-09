<?php

declare(strict_types=1);

namespace PhpEpub\Build;

use PhpEpub\BookTemplate;
use PhpEpub\BuildException;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ZipException;
use PhpEpub\ZipHandler;

/**
 * Assembles an EPUB 3 package with an EPUB 2 NCX from documents and resources: the package document, the navigation
 * document, the NCX and the container, written as an OCF archive.
 *
 * @internal
 */
final class BookPackage
{
    private const string NCX_MEDIA_TYPE = 'application/x-dtbncx+xml';

    /**
     * @var array<string, string> Path relative to the "EPUB" folder => content.
     */
    private array $files = [];

    /**
     * @var list<array{id: string, href: string, type: string, properties: string}>
     */
    private array $manifest = [];

    /**
     * @var list<string>
     */
    private array $spine = [];

    /**
     * @var list<array{title: string, href: string}>
     */
    private array $toc = [];

    /**
     * @var list<array{type: string, title: string, href: string}>
     */
    private array $landmarks = [];

    private ?string $coverId = null;

    /**
     * @param list<string> $authors
     * @param string $date Already in ISO 8601 form, or "".
     * @param 'text'|'images' $kind Whether the book is reflowable text or fixed-layout images.
     */
    public function __construct(
        private readonly string $title,
        private readonly string $language,
        private readonly string $identifier,
        private readonly array $authors,
        private readonly string $description,
        private readonly string $publisher,
        private readonly string $date,
        private readonly string $direction,
        private readonly string $kind
    ) {
    }

    /**
     * Adds an XHTML document to the manifest and the reading order.
     */
    public function addDocument(string $id, string $href, string $xhtml, string $properties = ''): void
    {
        $this->addResource($id, $href, $xhtml, 'application/xhtml+xml', $properties);
        $this->spine[] = $id;
    }

    public function addResource(string $id, string $href, string $content, string $mediaType, string $properties = ''): void
    {
        $this->files[$href] = $content;
        $this->manifest[] = ['id' => $id, 'href' => $href, 'type' => $mediaType, 'properties' => $properties];
    }

    public function addTocEntry(string $title, string $href): void
    {
        $this->toc[] = ['title' => $title, 'href' => $href];
    }

    public function addLandmark(string $type, string $title, string $href): void
    {
        $this->landmarks[] = ['type' => $type, 'title' => $title, 'href' => $href];
    }

    /**
     * Marks a resource added with addResource() (an image) as the cover.
     */
    public function setCover(string $id): void
    {
        $this->coverId = $id;
    }

    /**
     * An XHTML content document, valid in EPUB 3 (and readable in EPUB 2).
     *
     * @param string $head Extra markup for <head>, after the title.
     * @param string $body The body markup; it must be well-formed XML.
     */
    public static function document(string $title, string $language, string $direction, string $head, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n<!DOCTYPE html>\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="' . self::escape($language) . '" lang="' . self::escape($language) . '" dir="' . $direction . '">'
            . '<head><title>' . self::escape($title) . '</title>' . $head . '</head>'
            . '<body>' . $body . "</body></html>\n";
    }

    /**
     * @throws BuildException If the archive cannot be written.
     */
    public function toEpub(): string
    {
        $files = [
            'mimetype' => 'application/epub+zip',
            'META-INF/container.xml' => BookTemplate::container('EPUB/package.opf'),
            'EPUB/package.opf' => $this->packageDocument(),
            'EPUB/nav.xhtml' => $this->navigationDocument(),
            'EPUB/toc.ncx' => $this->ncx(),
        ];
        foreach ($this->files as $href => $content) {
            $files['EPUB/' . $href] = $content;
        }

        return self::archive($files);
    }

    /**
     * @param array<string, string> $files
     */
    private static function archive(array $files): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epubbuild_' . bin2hex(random_bytes(16));
        @mkdir($directory, 0700) || throw new BuildException("Failed to create temporary directory: {$directory}");
        $helper = new FileSystemHelper();

        try {
            foreach ($files as $path => $content) {
                $target = $directory . DIRECTORY_SEPARATOR . 'book' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                $written = (is_dir(dirname($target)) || @mkdir(dirname($target), 0700, true)) && @file_put_contents($target, $content) !== false;
                $written || throw new BuildException("Failed to write the book file: {$path}");
            }

            $archive = $directory . DIRECTORY_SEPARATOR . 'book.epub';
            try {
                (new ZipHandler())->compress($directory . DIRECTORY_SEPARATOR . 'book', $archive);
            } catch (ZipException $exception) {
                throw new BuildException('Failed to package the book: ' . $exception->getMessage(), 0, $exception);
            }

            return FileSystemHelper::readFile($archive) ?? throw new BuildException('Failed to read the packaged book');
        } finally {
            $helper->deleteDirectory($directory);
        }
    }

    private function packageDocument(): string
    {
        $metadata = '<dc:identifier id="pub-id">' . self::escape($this->identifier) . "</dc:identifier>\n"
            . '    <dc:title>' . self::escape($this->title) . "</dc:title>\n";
        foreach ($this->authors as $author) {
            $metadata .= '    <dc:creator>' . self::escape($author) . "</dc:creator>\n";
        }

        $metadata .= '    <dc:language>' . self::escape($this->language) . "</dc:language>\n";
        $this->description === '' || $metadata .= '    <dc:description>' . self::escape($this->description) . "</dc:description>\n";
        $this->publisher === '' || $metadata .= '    <dc:publisher>' . self::escape($this->publisher) . "</dc:publisher>\n";
        $this->date === '' || $metadata .= '    <dc:date>' . self::escape($this->date) . "</dc:date>\n";
        $metadata .= '    <meta property="dcterms:modified">' . gmdate('Y-m-d\TH:i:s\Z') . "</meta>\n";
        $this->coverId === null || $metadata .= '    <meta name="cover" content="' . self::escape($this->coverId) . "\"/>\n";

        if ($this->kind === 'images') {
            $metadata .= "    <meta property=\"rendition:layout\">pre-paginated</meta>\n"
                . "    <meta property=\"rendition:orientation\">auto</meta>\n"
                . "    <meta property=\"rendition:spread\">auto</meta>\n"
                . "    <meta property=\"schema:accessMode\">visual</meta>\n"
                . "    <meta property=\"schema:accessibilityFeature\">none</meta>\n"
                . "    <meta property=\"schema:accessibilityHazard\">none</meta>\n"
                . "    <meta property=\"schema:accessibilitySummary\">Fixed-layout pages made of images; the text in them is not available as text.</meta>\n";
        } else {
            $metadata .= "    <meta property=\"schema:accessMode\">textual</meta>\n"
                . "    <meta property=\"schema:accessibilityFeature\">tableOfContents</meta>\n"
                . "    <meta property=\"schema:accessibilityHazard\">none</meta>\n"
                . "    <meta property=\"schema:accessibilitySummary\">Reflowable text with a table of contents.</meta>\n";
        }

        $manifest = '    <item id="ncx" href="toc.ncx" media-type="' . self::NCX_MEDIA_TYPE . "\"/>\n"
            . "    <item id=\"nav\" href=\"nav.xhtml\" media-type=\"application/xhtml+xml\" properties=\"nav\"/>\n";
        foreach ($this->manifest as $item) {
            $properties = $item['properties'] === '' ? '' : ' properties="' . self::escape($item['properties']) . '"';
            $manifest .= '    <item id="' . self::escape($item['id']) . '" href="' . self::escape($item['href']) . '" media-type="' . $item['type'] . '"' . $properties . "/>\n";
        }

        $spine = '';
        foreach ($this->spine as $idref) {
            $spine .= '    <itemref idref="' . self::escape($idref) . "\"/>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id" xml:lang="' . self::escape($this->language) . "\">\n"
            . "  <metadata xmlns:dc=\"http://purl.org/dc/elements/1.1/\">\n    " . $metadata . "  </metadata>\n"
            . "  <manifest>\n" . $manifest . "  </manifest>\n"
            . '  <spine toc="ncx" page-progression-direction="' . $this->direction . "\">\n" . $spine . "  </spine>\n"
            . "</package>\n";
    }

    private function navigationDocument(): string
    {
        $items = '';
        foreach ($this->toc as $entry) {
            $items .= '<li><a href="' . self::escape($entry['href']) . '">' . self::escape($entry['title']) . "</a></li>\n";
        }

        $landmarks = '';
        foreach ($this->landmarks as $landmark) {
            $landmarks .= '<li><a epub:type="' . $landmark['type'] . '" href="' . self::escape($landmark['href']) . '">' . self::escape($landmark['title']) . "</a></li>\n";
        }

        return self::document(
            $this->title,
            $this->language,
            $this->direction,
            '',
            "<nav epub:type=\"toc\" id=\"toc\"><h1>Contents</h1>\n<ol>\n" . $items . "</ol></nav>\n"
            . ($landmarks === '' ? '' : "<nav epub:type=\"landmarks\" hidden=\"hidden\"><h2>Landmarks</h2>\n<ol>\n" . $landmarks . "</ol></nav>\n")
        );
    }

    private function ncx(): string
    {
        $points = '';
        foreach ($this->toc as $index => $entry) {
            $number = $index + 1;
            $points .= "    <navPoint id=\"navpoint-{$number}\" playOrder=\"{$number}\"><navLabel><text>" . self::escape($entry['title'])
                . '</text></navLabel><content src="' . self::escape($entry['href']) . "\"/></navPoint>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1" xml:lang="' . self::escape($this->language) . "\">\n"
            . '  <head><meta name="dtb:uid" content="' . self::escape($this->identifier) . "\"/><meta name=\"dtb:depth\" content=\"1\"/>"
            . "<meta name=\"dtb:totalPageCount\" content=\"0\"/><meta name=\"dtb:maxPageNumber\" content=\"0\"/></head>\n"
            . '  <docTitle><text>' . self::escape($this->title) . "</text></docTitle>\n"
            . "  <navMap>\n" . $points . "  </navMap>\n</ncx>\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
