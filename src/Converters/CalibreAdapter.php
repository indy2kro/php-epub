<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\Exception;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ZipHandler;

class CalibreAdapter implements ConverterInterface
{
    /**
     * @var array{calibre_path: string, extra_args: string|list<string>}
     */
    private array $options;

    /**
     * CalibreAdapter constructor.
     *
     * @param array{calibre_path?: string, extra_args?: string|list<string>} $options
     *        calibre_path: path to ebook-convert.
     *        extra_args: extra ebook-convert arguments as a list (each one is shell-escaped).
     *        Passing a single string is deprecated: it is inserted into the command unescaped.
     */
    public function __construct(
        array $options = [],
        private readonly FileSystemHelper $helper = new FileSystemHelper(),
        private readonly ZipHandler $zipHandler = new ZipHandler()
    ) {
        $defaultOptions = [
            'calibre_path' => '/usr/bin/ebook-convert',
            'extra_args' => [],
        ];

        $this->options = array_merge($defaultOptions, $options);
    }

    /**
     * Converts an EPUB with Calibre's ebook-convert.
     *
     * @param string $inputFile An .epub file, or a directory with an extracted EPUB
     *                          (packaged into a temporary .epub first, so Converter works with Calibre).
     * @param string $outputPath The output file; its extension selects the format (.mobi, .azw3, .pdf, ...).
     *
     * @throws Exception If Calibre is missing or the conversion fails.
     */
    public function convert(string $inputFile, string $outputPath): void
    {
        $calibrePath = $this->options['calibre_path'];

        if (! $this->helper->fileExists($calibrePath)) {
            throw new Exception('Calibre tool not found at path: ' . $calibrePath);
        }

        if (is_dir($inputFile)) {
            $temporaryEpub = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'calibre_' . bin2hex(random_bytes(8)) . '.epub';
            $this->zipHandler->compress($inputFile, $temporaryEpub);

            try {
                $this->run($calibrePath, $temporaryEpub, $outputPath);
            } finally {
                @unlink($temporaryEpub);
            }

            return;
        }

        if (! $this->helper->fileExists($inputFile)) {
            throw new Exception("EPUB file not found: {$inputFile}");
        }

        $this->run($calibrePath, $inputFile, $outputPath);
    }

    /**
     * @throws Exception
     */
    private function run(string $calibrePath, string $inputFile, string $outputPath): void
    {
        // Every part is escaped; stderr is captured so failures carry Calibre's message.
        $command = escapeshellarg($calibrePath)
            . ' ' . escapeshellarg($inputFile)
            . ' ' . escapeshellarg($outputPath)
            . $this->extraArguments()
            . ' 2>&1';

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

    private function extraArguments(): string
    {
        $extraArgs = $this->options['extra_args'];

        if (is_string($extraArgs)) {
            if ($extraArgs === '') {
                return '';
            }

            trigger_error(
                'Passing CalibreAdapter extra_args as a string is deprecated; pass a list of arguments, which are escaped individually.',
                E_USER_DEPRECATED
            );

            return ' ' . $extraArgs;
        }

        return implode('', array_map(static fn (string $argument): string => ' ' . escapeshellarg($argument), $extraArgs));
    }
}
