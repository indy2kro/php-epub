<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\Exception;

class CalibreAdapter implements ConverterInterface
{
    /**
     * @var array<string, string>
     */
    private array $options;

    /**
     * CalibreAdapter constructor.
     *
     * @param array<string, string> $options Optional command-line options for Calibre.
     */
    public function __construct(array $options = [])
    {
        // Default options
        $defaultOptions = [
            'calibre_path' => '/usr/bin/ebook-convert', // Default path to Calibre's ebook-convert tool
            'extra_args' => '', // Additional command-line arguments
        ];

        // Merge default options with user-provided options
        $this->options = array_merge($defaultOptions, $options);
    }

    /**
     * Converts the EPUB content to a specified format using Calibre.
     *
     * @param string $outputPath The path where the converted file should be saved.
     *
     * @throws Exception If the conversion fails.
     */
    public function convert(string $inputFile, string $outputPath): void
    {
        $calibrePath = $this->options['calibre_path'];

        // Ensure the Calibre tool is available
        if (! file_exists($calibrePath)) {
            throw new Exception('Calibre tool not found at path: ' . $calibrePath);
        }

        if (! file_exists($inputFile)) {
            throw new Exception("EPUB file not found: {$inputFile}");
        }

        $command = sprintf(
            '%s %s %s %s',
            escapeshellcmd($this->options['calibre_path']),
            escapeshellarg($inputFile),
            escapeshellarg($outputPath),
            $this->options['extra_args']
        );

        // Execute the command
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception('Calibre conversion failed: ' . implode("\n", $output));
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new Exception('Calibre conversion failed');
        }
    }
}
