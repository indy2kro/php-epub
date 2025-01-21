<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Converters\ConverterInterface;

class Converter
{
    private readonly string $epubDirectory;

    /**
     * Converter constructor.
     *
     * @param string $epubDirectory The directory containing the extracted EPUB contents.
     * @param array<string, ConverterInterface> $adapters A map of format to converter adapters.
     */
    public function __construct(string $epubDirectory, private array $adapters)
    {
        if (! is_dir($epubDirectory)) {
            throw new Exception("EPUB directory does not exist: {$epubDirectory}");
        }

        $this->epubDirectory = $epubDirectory;
    }

    /**
     * Converts the EPUB to a specified format.
     *
     * @param string $format The format to convert to (e.g., 'pdf', 'mobi').
     * @param string $outputPath The path where the converted file should be saved.
     *
     * @throws Exception If the conversion fails or the format is not supported.
     */
    public function convert(string $format, string $outputPath): void
    {
        if (! isset($this->adapters[$format])) {
            throw new Exception("Conversion format not supported: {$format}");
        }

        $adapter = $this->adapters[$format];
        $adapter->convert($this->epubDirectory, $outputPath);
    }
}
