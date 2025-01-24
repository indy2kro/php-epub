<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\Exception;
use PhpEpub\Util\FileSystemHelper;

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
    public function __construct(array $options = [], private readonly FileSystemHelper $helper = new FileSystemHelper())
    {
        $defaultOptions = [
            'calibre_path' => '/usr/bin/ebook-convert',
            'extra_args' => '',
        ];

        $this->options = array_merge($defaultOptions, $options);
    }

    public function convert(string $inputFile, string $outputPath): void
    {
        $calibrePath = $this->options['calibre_path'];

        if (! $this->helper->fileExists($calibrePath)) {
            throw new Exception('Calibre tool not found at path: ' . $calibrePath);
        }

        if (! $this->helper->fileExists($inputFile)) {
            throw new Exception("EPUB file not found: {$inputFile}");
        }

        $command = sprintf(
            '%s %s %s %s',
            escapeshellcmd($calibrePath),
            escapeshellarg($inputFile),
            escapeshellarg($outputPath),
            $this->options['extra_args']
        );

        // Execute the command
        $output = [];
        $returnVar = 0;
        $this->helper->exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception('Calibre conversion failed: ' . implode("\n", $output));
        }

        if (! $this->helper->fileExists($outputPath) || $this->helper->fileSize($outputPath) === 0) {
            throw new Exception('Calibre conversion failed');
        }
    }
}
