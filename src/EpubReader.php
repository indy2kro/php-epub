<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\CoverLocator;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\HtmlText;
use PhpEpub\Util\PathResolver;
use PhpEpub\Util\SpineText;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads a book without extracting it: nothing is written to disk (a book from a stream, and a string
 * before PHP 8.4, is buffered in one private scratch file that close() deletes). Entries are read from
 * the archive when asked for, so metadata, the table of contents, the cover and the text of a book cost
 * what they read, which suits a server that inspects an upload per request.
 *
 * The book is read-only: there is no save(), content manager or conversion, and the metadata and
 * table of contents objects refuse to write with a ReadOnlyException. Use EpubFile to edit or convert.
 *
 * The Limits apply as they do for EpubFile, measured on the bytes actually read (declared sizes are not
 * trusted): the entry count and the declared total are checked when the book is opened, then every entry
 * read counts once towards the total size and is checked against the compression ratio, and XML and XHTML
 * documents against their size caps.
 */
final class EpubReader
{
    private const int CHUNK_SIZE = 65536;

    /**
     * Entries larger than this are checked against the compression ratio limit.
     */
    private const int RATIO_CHECK_THRESHOLD = 1024 * 1024;

    private ?ZipArchive $zip;

    /**
     * Normalized path => entry index, for the file entries.
     *
     * @var array<string, int>
     */
    private array $entries = [];

    /**
     * Entry index => bytes already counted towards the total, so reading an entry again costs nothing.
     *
     * @var array<int, int>
     */
    private array $charged = [];

    private int $total = 0;

    private readonly Limits $limits;

    private readonly XmlParser $xmlParser;

    private readonly PathResolver $paths;

    private readonly Metadata $metadata;

    private readonly Manifest $manifest;

    private readonly Spine $spine;

    private string $opfPath;

    /**
     * @param string|null $scratchDirectory A private directory holding the buffered archive, deleted by close().
     */
    private function __construct(
        ZipArchive $zip,
        ?Limits $limits,
        ?XmlParser $xmlParser,
        private ?string $scratchDirectory = null
    ) {
        $this->zip = $zip;
        $this->limits = $limits ?? Limits::default();
        $this->xmlParser = $xmlParser ?? $this->limits->xmlParser();
        $this->paths = new PathResolver();

        try {
            $this->indexEntries($zip);

            $parser = new Parser($this->xmlParser, $this->paths);
            $this->opfPath = $parser->locatePackage($this->readXml('META-INF/container.xml'));
            $opf = $this->readXml($this->opfPath);
            $parser->assertPackage($opf);

            $this->metadata = new Metadata($opf, $this->opfPath, true);
            $this->manifest = new Manifest($opf, $this->opfPath);
            $this->spine = new Spine($opf, $this->manifest);
        } catch (\Throwable $throwable) {
            $this->close();

            throw $throwable;
        }
    }

    /**
     * Opens a book file.
     *
     * @param Limits|null $limits The limits for an untrusted book (Limits::web() for uploads); Limits::default() when null.
     * @param XmlParser|null $xmlParser Replaces the parser the limits would build (its own size cap then applies).
     *
     * @throws ZipException If the file is not a readable archive or exceeds a limit.
     * @throws InvalidEpubException If the archive is not a valid EPUB (XmlException for unreadable or oversized XML).
     */
    public static function open(string $filePath, ?Limits $limits = null, ?XmlParser $xmlParser = null): self
    {
        file_exists($filePath) || throw new ZipException("ZIP file does not exist: {$filePath}");

        return new self(self::openArchive($filePath), $limits, $xmlParser);
    }

    /**
     * Opens a book held in a string, e.g. an upload. Where ext-zip has ZipArchive::openString() the data is read in place; otherwise
     * it is buffered in a private scratch file that close() deletes.
     *
     * @throws ZipException If the data is not a readable archive or exceeds a limit.
     * @throws InvalidEpubException If the archive is not a valid EPUB.
     */
    public static function openString(string $data, ?Limits $limits = null, ?XmlParser $xmlParser = null): self
    {
        return self::fromString($data, $limits, $xmlParser, ! method_exists(ZipArchive::class, 'openString'));
    }

    /**
     * Opens a book read from a stream (from its current position to its end). The stream stays open and is
     * not rewound. The data is buffered in a private scratch file that close() deletes.
     *
     * @param resource $stream A readable stream.
     *
     * @throws Exception If the stream cannot be read.
     * @throws ZipException If the data is not a readable archive or exceeds a limit.
     * @throws InvalidEpubException If the archive is not a valid EPUB.
     */
    public static function openStream($stream, ?Limits $limits = null, ?XmlParser $xmlParser = null): self
    {
        is_resource($stream) && get_resource_type($stream) === 'stream' || throw new Exception('A stream resource is required');

        [$directory, $archive] = self::scratchArchive();

        try {
            $output = @fopen($archive, 'wb') ?: throw new Exception('Failed to buffer the EPUB stream');
            $copied = @stream_copy_to_stream($stream, $output);
            fclose($output);
            $copied === false && throw new Exception('Failed to read the EPUB stream');

            return new self(self::openArchive($archive), $limits, $xmlParser, $directory);
        } catch (\Throwable $throwable) {
            (new FileSystemHelper())->deleteDirectory($directory);

            throw $throwable;
        }
    }

    /**
     * @param bool $buffer Whether to buffer the data in a scratch file (needed where ZipArchive::openString() does not exist).
     *
     * @internal Public for the tests, which cover the buffered path on every PHP version.
     */
    public static function fromString(string $data, ?Limits $limits, ?XmlParser $xmlParser, bool $buffer): self
    {
        if (! $buffer) {
            // ZipArchive::openString() is an instance method that only newer ext-zip builds have (the minimum PHP
            // version does not); openString() checks for it. Whatever it throws is reported as a ZipException.
            $zip = new ZipArchive();
            try {
                $opened = $zip->openString($data, ZipArchive::RDONLY); // @phpstan-ignore method.notFound
            } catch (\Throwable $throwable) {
                throw new ZipException('Failed to open the EPUB data as a ZIP archive', 0, $throwable);
            }

            $opened === true || throw new ZipException('Failed to open the EPUB data as a ZIP archive');

            return new self($zip, $limits, $xmlParser);
        }

        [$directory, $archive] = self::scratchArchive();

        try {
            @file_put_contents($archive, $data) !== false || throw new Exception('Failed to buffer the EPUB data');

            return new self(self::openArchive($archive), $limits, $xmlParser, $directory);
        } catch (\Throwable $throwable) {
            (new FileSystemHelper())->deleteDirectory($directory);

            throw $throwable;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * A clone would share the archive handle and the scratch file with the original.
     *
     * @throws Exception Always.
     */
    public function __clone()
    {
        // The clone owns neither the archive nor the scratch file; keep its destructor away from them.
        $this->zip = null;
        $this->scratchDirectory = null;

        throw new Exception('EpubReader cannot be cloned; open the book again instead.');
    }

    /**
     * Closes the archive and deletes the scratch file, if any. The reader is then unusable. Safe to call more
     * than once; the destructor does it too.
     */
    public function close(): void
    {
        if ($this->zip instanceof ZipArchive) {
            @$this->zip->close();
            $this->zip = null;
        }

        $this->entries = [];

        if ($this->scratchDirectory !== null) {
            (new FileSystemHelper())->deleteDirectory($this->scratchDirectory);
            $this->scratchDirectory = null;
        }
    }

    /**
     * The package metadata. Read-only: its save() throws a ReadOnlyException, and changes made in memory
     * are never written anywhere.
     */
    public function getMetadata(): Metadata
    {
        $this->assertOpen();

        return $this->metadata;
    }

    public function getManifest(): Manifest
    {
        $this->assertOpen();

        return $this->manifest;
    }

    public function getSpine(): Spine
    {
        $this->assertOpen();

        return $this->spine;
    }

    /**
     * The table of contents (EPUB 3 navigation document and EPUB 2 NCX), read when asked for.
     * Read-only: every change throws a ReadOnlyException.
     */
    public function getTableOfContents(): TableOfContents
    {
        $this->assertOpen();

        return new TableOfContents('', $this->manifest, $this->xmlParser, $this->paths, null, $this->spine, fn (string $path): string => $this->readXmlSource($path));
    }

    /**
     * The cover image, found as EpubFile::getCoverImage() does. A cover page that is too large to read has no
     * first image: the lookup never fails for that.
     */
    public function getCoverImage(): ?ManifestItem
    {
        $this->assertOpen();

        return CoverLocator::find($this->manifest, $this->metadata, $this->readMarkup(...));
    }

    /**
     * The plain text of the book's XHTML and HTML documents in reading order, as EpubFile::getText().
     *
     * @return array<string, string>
     *
     * @throws InvalidEpubException If a document is larger than the limit (Limits::$maxHtmlBytes).
     * @throws ZipException If a document exceeds the limits while it is read.
     */
    public function getText(bool $linearOnly = true): array
    {
        $this->assertOpen();

        return SpineText::collect(
            $this->spine,
            $linearOnly,
            $this->hasContent(...),
            fn (string $path): string => HtmlText::extract($this->readMarkup($path))
        );
    }

    /**
     * Whether the book has a file at a path relative to the book root.
     */
    public function hasContent(string $path): bool
    {
        $this->assertOpen();

        try {
            return isset($this->entries[$this->paths->normalize($path)]);
        } catch (InvalidEpubException) {
            return false;
        }
    }

    /**
     * The paths of the book's files (sorted), relative to the book root.
     *
     * @return list<string>
     */
    public function getContentPaths(): array
    {
        $this->assertOpen();

        $paths = array_keys($this->entries);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Reads a file of the book, e.g. the cover image.
     *
     * The whole file is held in memory: keep $maxBytes within the memory_limit. Without it only the limits of
     * the book apply (the total size, and the compression ratio).
     *
     * @param string $path The path relative to the book root.
     * @param int|null $maxBytes Refuse a file larger than this.
     *
     * @throws InvalidEpubException If the file does not exist, is larger than $maxBytes or the path leaves the book.
     * @throws ZipException If the file exceeds the limits while it is read.
     */
    public function getContent(string $path, ?int $maxBytes = null): string
    {
        $this->assertOpen();

        return $this->read(
            $path,
            $maxBytes ?? PHP_INT_MAX,
            static fn (string $name, int $cap): InvalidEpubException => new InvalidEpubException("File is larger than {$cap} bytes: {$name}"),
            static fn (string $name): InvalidEpubException => new InvalidEpubException("Content file does not exist: {$name}")
        );
    }

    /**
     * @throws Exception If the reader is closed.
     */
    private function assertOpen(): void
    {
        $this->zip instanceof ZipArchive || throw new Exception('The EPUB reader is closed.');
    }

    private static function openArchive(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ZipException("Failed to open ZIP file: {$path}");
        }

        return $zip;
    }

    /**
     * @return array{string, string} A new private directory and the path of the archive inside it.
     */
    private static function scratchArchive(): array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epubio_' . bin2hex(random_bytes(16));
        @mkdir($directory, 0700) || throw new Exception("Failed to create temporary directory: {$directory}");

        return [$directory, $directory . DIRECTORY_SEPARATOR . 'book.epub'];
    }

    /**
     * Checks the archive as ZipHandler::extract() does before it writes anything: entry count, names that
     * stay inside the book, names unique after case folding, and the declared total size.
     *
     * @throws ZipException
     */
    private function indexEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles > $this->limits->maxEntries) {
            throw new ZipException("ZIP file has too many entries ({$zip->numFiles} > {$this->limits->maxEntries})");
        }

        $folded = [];
        $declared = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                throw new ZipException("Failed to read entry {$index} of the ZIP file");
            }

            $name = $stat['name'];
            try {
                $path = $this->paths->normalize($name);
            } catch (InvalidEpubException $exception) {
                throw new ZipException("ZIP entry resolves outside the EPUB: {$name}", 0, $exception);
            }

            if (str_ends_with($name, '/') || str_ends_with($name, '\\')) {
                continue;
            }

            $key = ZipHandler::foldName($path);
            if (isset($folded[$key])) {
                throw new ZipException("ZIP entries differ only in case: {$folded[$key]} and {$name}");
            }

            $folded[$key] = $name;
            $this->entries[$path] = $index;

            $declared += $stat['size'];
            if ($declared > $this->limits->maxUncompressedBytes) {
                throw new ZipException("ZIP file exceeds the maximum uncompressed size of {$this->limits->maxUncompressedBytes} bytes");
            }
        }
    }

    /**
     * An XML document of the book, parsed (with the XmlParser's protections), under the XML size cap.
     *
     * @throws XmlException
     */
    private function readXml(string $path): SimpleXMLElement
    {
        return $this->xmlParser->parseString($this->readXmlSource($path), $path);
    }

    /**
     * The bytes of an XML document of the book, under the XML size cap.
     *
     * @throws XmlException If the document is missing or too large.
     */
    private function readXmlSource(string $path): string
    {
        $this->assertOpen();

        return $this->read(
            $path,
            $this->limits->maxXmlBytes,
            static fn (string $name, int $cap): XmlException => new XmlException("XML file is larger than the limit of {$cap} bytes: {$name}"),
            static fn (string $name): XmlException => new XmlException("XML file not found: {$name}")
        );
    }

    /**
     * An XHTML or HTML document of the book, under the markup size cap.
     *
     * @throws InvalidEpubException If the document is missing or too large.
     */
    private function readMarkup(string $path): string
    {
        return $this->read(
            $path,
            $this->limits->maxHtmlBytes,
            static fn (string $name, int $cap): InvalidEpubException => new InvalidEpubException("Document is larger than the limit of {$cap} bytes: {$name}"),
            static fn (string $name): InvalidEpubException => new InvalidEpubException("Content file does not exist: {$name}")
        );
    }

    /**
     * Reads one entry into memory, enforcing the limits on the bytes actually read.
     *
     * @param \Closure(string, int): \Throwable $tooLarge The exception for an entry over $cap bytes.
     * @param \Closure(string): \Throwable $missing The exception for a path that is not in the book.
     *
     * @throws \Throwable The exceptions made by the closures, and ZipException.
     */
    private function read(string $path, int $cap, \Closure $tooLarge, \Closure $missing): string
    {
        $zip = $this->zip ?? throw new Exception('The EPUB reader is closed.');
        $name = $this->paths->normalize($path);
        $index = $this->entries[$name] ?? throw $missing($name);

        $stat = $zip->statIndex($index);
        $stat !== false || throw new ZipException("Failed to read ZIP entry: {$name}");

        // Declared sizes are not trusted, but they spare reading an entry that cannot fit.
        $stat['size'] > $cap && throw $tooLarge($name, $cap);

        $input = @$zip->getStreamIndex($index);
        if ($input === false) {
            throw new ZipException("Failed to read ZIP entry: {$name} ({$zip->getStatusString()})");
        }

        $content = '';
        $read = 0;

        try {
            while (! feof($input)) {
                $chunk = @fread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new ZipException("Failed to read ZIP entry: {$name}");
                }

                $read += strlen($chunk);
                $read > $cap && throw $tooLarge($name, $cap);
                $this->charge($index, $read, $name);

                if ($read > self::RATIO_CHECK_THRESHOLD && $read > max(1, $stat['comp_size']) * $this->limits->maxCompressionRatio) {
                    throw new ZipException("ZIP entry exceeds the maximum compression ratio of {$this->limits->maxCompressionRatio}: {$name}");
                }

                $content .= $chunk;
            }
        } finally {
            fclose($input);
        }

        return $content;
    }

    /**
     * Counts the bytes of an entry read so far towards the total (once per entry, however often it is read).
     *
     * @throws ZipException If the total exceeds the limit.
     */
    private function charge(int $index, int $read, string $name): void
    {
        $extra = $read - ($this->charged[$index] ?? 0);
        if ($extra <= 0) {
            return;
        }

        $this->charged[$index] = $read;
        $this->total += $extra;

        if ($this->total > $this->limits->maxUncompressedBytes) {
            throw new ZipException("ZIP file exceeds the maximum uncompressed size of {$this->limits->maxUncompressedBytes} bytes (reading {$name})");
        }
    }
}
