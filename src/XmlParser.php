<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\FileSystemHelper;
use SimpleXMLElement;

class XmlParser
{
    /**
     * @param int $maxBytes Largest document, in bytes, parse() and parseString() accept (no cap by default).
     */
    /**
     * The name error messages use for the file instead of its path (see withName()).
     */
    private ?string $name = null;

    public function __construct(public readonly int $maxBytes = PHP_INT_MAX)
    {
    }

    /**
     * A copy of this parser (of the same class, with the same limit) whose parse() and save() name the file $name in
     * their error messages instead of its path, e.g. the book-relative path of a file in an extracted book, so that
     * messages do not reveal the extraction directory.
     */
    public function withName(string $name): static
    {
        $named = clone $this;
        $named->name = $name;

        return $named;
    }

    /**
     * Loads an XML file and returns a SimpleXMLElement.
     *
     * Documents come from untrusted books, so entity declarations are rejected
     * and the parser never touches the network (DOCTYPE references are not fetched).
     *
     * @param string $filePath The path to the XML file.
     *
     * @throws XmlException If the XML file cannot be loaded or is larger than the size limit.
     */
    public function parse(string $filePath): SimpleXMLElement
    {
        $name = $this->name ?? $filePath;
        if (! file_exists($filePath)) {
            throw new XmlException("XML file not found: {$name}");
        }

        // Checked before reading, so an oversized document never gets into memory.
        $size = @filesize($filePath);
        if ($size !== false && $size > $this->maxBytes) {
            throw new XmlException("XML file is larger than the limit of {$this->maxBytes} bytes: {$name}");
        }

        $content = FileSystemHelper::readFile($filePath) ?? throw new XmlException("Failed to read XML file: {$name}");

        return $this->parseString($content, $name);
    }

    /**
     * Parses an XML document held in memory, with the same protections as parse().
     *
     * @param string $source Where the document comes from, for error messages.
     *
     * @throws XmlException If the document is not well-formed or declares entities.
     */
    public function parseString(string $content, string $source = 'string'): SimpleXMLElement
    {
        if (strlen($content) > $this->maxBytes) {
            throw new XmlException("XML document is larger than the limit of {$this->maxBytes} bytes: {$source}");
        }

        // Entity declarations enable expansion attacks; EPUB container, package and NCX files never need them.
        if (preg_match('/<!ENTITY/i', $content) === 1) {
            throw new XmlException("XML entity declarations are not allowed: {$source}");
        }

        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                $errors = libxml_get_errors();
                $detail = $errors === [] ? '' : sprintf(' (%s at line %d)', trim($errors[0]->message), $errors[0]->line);

                throw new XmlException("Failed to load XML file: {$source}{$detail}");
            }

            // The byte check above misses other encodings (e.g. UTF-16); the parsed DOCTYPE does not.
            if ($this->declaresEntities($xml)) {
                throw new XmlException("XML entity declarations are not allowed: {$source}");
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }
    }

    /**
     * Whether the document's internal DTD subset declares any (general or parameter) entity.
     */
    private function declaresEntities(SimpleXMLElement $xml): bool
    {
        $internalSubset = dom_import_simplexml($xml)->ownerDocument?->doctype?->internalSubset;

        return $internalSubset !== null && stripos($internalSubset, '<!ENTITY') !== false;
    }

    /**
     * Saves a SimpleXMLElement to a file. The document is written to a temporary file next to it and renamed into
     * place, so a failed or short write leaves the file as it was, never truncated.
     *
     * @param SimpleXMLElement $xml The XML element to save.
     * @param string $filePath The path where the XML should be saved.
     *
     * @throws Exception If the XML file cannot be saved (also when it exists and is not writable).
     */
    public function save(SimpleXMLElement $xml, string $filePath): void
    {
        $content = $xml->asXML();
        if (! is_string($content) || ! self::replaceFile($filePath, $content)) {
            throw new Exception('Failed to save XML file: ' . ($this->name ?? $filePath));
        }
    }

    /**
     * Writes $content to a temporary file in $filePath's directory, checks that every byte was written and renames
     * it over $filePath, which keeps its permissions. A file that exists but is not writable is refused, as writing
     * it in place would be (a rename only needs the directory to be writable). A symbolic link is followed, as a
     * write in place would: the file it points to is replaced and the link stays; a dangling link is refused. In a
     * directory where no file can be created, the file is written in place, as before.
     */
    private static function replaceFile(string $filePath, string $content): bool
    {
        if (is_link($filePath)) {
            $target = realpath($filePath);
            if ($target === false) {
                return false;
            }

            $filePath = $target;
        }

        $directory = dirname($filePath);
        if (! is_dir($directory) || is_dir($filePath) || (file_exists($filePath) && ! is_writable($filePath))) {
            return false;
        }

        // tempnam() falls back to the system temporary directory when it cannot create the file in $directory.
        $temporary = @tempnam($directory, '.xml-');
        if ($temporary === false || realpath(dirname($temporary)) !== realpath($directory)) {
            $temporary === false || @unlink($temporary);

            // No file can be created next to it (a read-only directory): the file itself may still be writable.
            return @file_put_contents($filePath, $content) === strlen($content);
        }

        $mode = file_exists($filePath) ? fileperms($filePath) & 0777 : 0666 & ~umask();
        $saved = @file_put_contents($temporary, $content) === strlen($content) && @chmod($temporary, $mode) && @rename($temporary, $filePath);
        $saved || @unlink($temporary);

        return $saved;
    }
}
