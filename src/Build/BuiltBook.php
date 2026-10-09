<?php

declare(strict_types=1);

namespace PhpEpub\Build;

use PhpEpub\BuildException;
use PhpEpub\EpubFile;

/**
 * A book made by BookBuilder.
 */
final readonly class BuiltBook
{
    /**
     * @param string $epub The EPUB file's bytes.
     * @param int $chapterCount The number of chapters (or comic pages) in the reading order.
     * @param list<string> $warnings What was left out: images that were not supplied or not usable.
     */
    public function __construct(public string $epub, public int $chapterCount, public array $warnings = [])
    {
    }

    /**
     * The EPUB file's bytes, e.g. for a download.
     */
    public function toString(): string
    {
        return $this->epub;
    }

    /**
     * @throws BuildException If the file cannot be written.
     */
    public function save(string $path): void
    {
        if (@file_put_contents($path, $this->epub) === false) {
            throw new BuildException("Failed to write the EPUB file: {$path}");
        }
    }

    /**
     * Opens the book for further editing; the caller owns the result (see EpubFile::cleanup()).
     *
     * @throws \PhpEpub\Exception If the book cannot be opened (it can, unless a limit of the ZIP handler is smaller
     *                            than the one used to build it).
     */
    public function open(): EpubFile
    {
        return EpubFile::openString($this->epub);
    }
}
