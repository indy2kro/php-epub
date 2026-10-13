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
     *
     * @throws \PhpEpub\ConversionException If the book cannot be converted (the code tells a refusal apart, see
     *         its CODE_* constants).
     */
    public function convert(string $epubDirectory, string $outputPath): void;
}
