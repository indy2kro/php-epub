<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * The resource limits applied to a book that is treated as untrusted input.
 *
 * Pass one to EpubFile::open(), openString(), openStream() or EpubReader instead of building a
 * ZipHandler and an XmlParser by hand. default() is the behaviour of the library without limits
 * configured; web() suits a public service that processes uploaded books per request.
 */
final readonly class Limits
{
    /**
     * @param int $maxEntries Maximum number of entries an archive may contain.
     * @param int $maxUncompressedBytes Maximum total size of the book's contents, measured on the bytes actually read.
     * @param int $maxCompressionRatio Maximum uncompressed/compressed ratio for a single entry larger than 1 MiB.
     * @param int $maxXmlBytes Maximum size of one XML document (container, package, navigation, NCX, encryption).
     * @param int $maxHtmlBytes Maximum size of one XHTML or HTML content document the library parses (text extraction,
     *                          the cover page lookup and, through EpubFile::convert(), the conversion adapters).
     *                          An adapter used directly on a directory takes its cap from its EpubDocumentLoader.
     * @param int $maxArchiveBytes Maximum size of the (compressed) archive that openString() and openStream() accept,
     *                             enforced while the data is buffered. A book opened from a path is already on disk.
     *
     * @throws Exception If a limit is not positive.
     */
    public function __construct(
        public int $maxEntries = 10_000,
        public int $maxUncompressedBytes = 1024 * 1024 * 1024,
        public int $maxCompressionRatio = 100,
        public int $maxXmlBytes = PHP_INT_MAX,
        public int $maxHtmlBytes = PHP_INT_MAX,
        public int $maxArchiveBytes = PHP_INT_MAX
    ) {
        foreach (['maxEntries' => $maxEntries, 'maxUncompressedBytes' => $maxUncompressedBytes, 'maxCompressionRatio' => $maxCompressionRatio, 'maxXmlBytes' => $maxXmlBytes, 'maxHtmlBytes' => $maxHtmlBytes, 'maxArchiveBytes' => $maxArchiveBytes] as $name => $value) {
            $value > 0 || throw new Exception("The limit {$name} must be positive, got {$value}");
        }
    }

    /**
     * The limits of a library without any configured: 10,000 entries, 1 GiB, ratio 100, and no cap
     * on the size of a single XML or HTML document or of the archive.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * Limits for a service that processes untrusted uploads per request: 2,000 entries, 200 MiB
     * of contents, ratio 100, 8 MiB per XML or XHTML document and a 100 MiB archive.
     */
    public static function web(): self
    {
        return new self(2_000, 200 * 1024 * 1024, 100, 8 * 1024 * 1024, 8 * 1024 * 1024, 100 * 1024 * 1024);
    }

    /**
     * Copies a stream to an open file, stopping one byte past maxArchiveBytes.
     *
     * @param resource $stream
     * @param resource $output
     *
     * @throws ZipException If the stream is larger than the cap or cannot be read.
     *
     * @internal
     */
    public function bufferStream($stream, $output): void
    {
        $copied = @stream_copy_to_stream($stream, $output, $this->maxArchiveBytes === PHP_INT_MAX ? null : $this->maxArchiveBytes + 1);
        $copied === false && throw new Exception('Failed to read the EPUB stream');
        $this->assertArchiveSize($copied);
    }

    /**
     * @throws ZipException If $bytes exceeds maxArchiveBytes.
     *
     * @internal
     */
    public function assertArchiveSize(int $bytes): void
    {
        $bytes <= $this->maxArchiveBytes || throw new ZipException("The archive is larger than the limit of {$this->maxArchiveBytes} bytes (maxArchiveBytes)");
    }

    public function zipHandler(): ZipHandler
    {
        return new ZipHandler($this->maxEntries, $this->maxUncompressedBytes, $this->maxCompressionRatio);
    }

    public function xmlParser(): XmlParser
    {
        return new XmlParser($this->maxXmlBytes);
    }
}
