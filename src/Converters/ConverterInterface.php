<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

interface ConverterInterface
{
    /**
     * Converts the EPUB content to a specified format.
     *
     * @param string $epubDirectory The directory containing the extracted EPUB contents.
     * @param string $outputPath The path where the converted file should be saved.
     */
    public function convert(string $epubDirectory, string $outputPath): void;
}
