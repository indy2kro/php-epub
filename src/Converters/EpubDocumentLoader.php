<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\ConversionException;
use PhpEpub\InvalidEpubException;
use PhpEpub\Manifest;
use PhpEpub\ManifestItem;
use PhpEpub\Metadata;
use PhpEpub\Parser;
use PhpEpub\Spine;
use PhpEpub\Util\PathResolver;
use PhpEpub\XmlParser;

/**
 * Reads an extracted EPUB the way a reader would: the spine documents in order,
 * plus the title and authors. Used by the HTML-based PDF converters.
 *
 * Book content is untrusted, so scripts are removed and image sources are
 * rewritten to absolute paths of files inside the book; anything else
 * (absolute paths, other schemes, remote URLs, paths escaping the book,
 * missing files) is blanked so renderers never read outside the book.
 */
final readonly class EpubDocumentLoader
{
    private const array XHTML_MEDIA_TYPES = ['application/xhtml+xml', 'text/html'];

    public function __construct(
        private XmlParser $xmlParser = new XmlParser(),
        private PathResolver $paths = new PathResolver()
    ) {
    }

    /**
     * @throws ConversionException If the directory holds no readable book.
     */
    public function load(string $epubDirectory): EpubDocument
    {
        $root = is_dir($epubDirectory) ? realpath($epubDirectory) : false;
        if ($root === false) {
            throw new ConversionException("EPUB directory does not exist: {$epubDirectory}");
        }

        if (is_file($root . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'container.xml')) {
            return $this->loadPackage($root);
        }

        // Legacy layout: a single content.xhtml without an OPF package.
        if (is_file($root . DIRECTORY_SEPARATOR . 'content.xhtml')) {
            return new EpubDocument('', [], [$this->prepareChapter($root, 'content.xhtml')]);
        }

        throw new ConversionException("No EPUB package found in: {$epubDirectory}");
    }

    private function loadPackage(string $root): EpubDocument
    {
        $opfPath = (new Parser($this->xmlParser))->parse($root);
        $opfFile = $this->paths->resolve($root, $opfPath);
        $opfXml = $this->xmlParser->parse($opfFile);

        $metadata = new Metadata($opfXml, $opfFile);
        $spine = new Spine($opfXml, new Manifest($opfXml, $opfPath));

        $chapters = [];
        foreach ($spine->getItems() as $spineItem) {
            $item = $spineItem->item;
            if ($item instanceof ManifestItem && $item->path !== '' && in_array($item->mediaType, self::XHTML_MEDIA_TYPES, true)) {
                $chapters[] = $this->prepareChapter($root, $item->path);
            }
        }

        return new EpubDocument($metadata->getTitle(), array_values($metadata->getAuthors()), $chapters);
    }

    /**
     * Returns the <body> HTML of a document, with scripts removed and image sources confined to the book.
     */
    private function prepareChapter(string $root, string $path): string
    {
        $file = $this->paths->resolve($root, $path);
        $content = is_file($file) ? @file_get_contents($file) : false;
        if ($content === false) {
            throw new ConversionException("Failed to read content from: {$path}");
        }

        $body = preg_match('#<body\b[^>]*>(.*)</body>#is', $content, $match) === 1 ? $match[1] : $content;
        $body = (string) preg_replace('#<script\b[^>]*>.*?</script>|<script\b[^>]*/>#is', '', $body);

        $directory = dirname($path) === '.' ? '' : dirname($path) . '/';

        return (string) preg_replace_callback(
            '#\b(src|xlink:href)(\s*=\s*)(["\'])(.*?)\3#is',
            fn (array $attribute): string => $attribute[1] . $attribute[2] . $attribute[3]
                . $this->resolveSource($root, $directory, $attribute[4]) . $attribute[3],
            $body
        );
    }

    private function resolveSource(string $root, string $directory, string $source): string
    {
        $source = trim($source);

        if (str_starts_with(strtolower($source), 'data:')) {
            return $source;
        }

        // Remote URLs, file:// and any other scheme.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $source) === 1) {
            return '';
        }

        try {
            $file = $this->paths->resolve($root, $directory . rawurldecode(explode('#', $source, 2)[0]));
        } catch (InvalidEpubException) {
            return '';
        }

        $real = realpath($file);
        if ($real === false || ! is_file($real) || ! str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return '';
        }

        return str_replace('\\', '/', $real);
    }
}
