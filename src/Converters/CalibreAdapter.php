<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\Exception;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ZipHandler;

class CalibreAdapter implements ConverterInterface
{
    /**
     * @var array{calibre_path: string, extra_args: string|list<string>, timeout: int|null}
     */
    private array $options;

    /**
     * CalibreAdapter constructor.
     *
     * @param array{calibre_path?: string, extra_args?: string|list<string>, timeout?: int|null} $options
     *        calibre_path: path to ebook-convert.
     *        extra_args: extra ebook-convert arguments as a list, each passed to Calibre as it is.
     *        Passing a single string is deprecated: it is split into arguments at spaces (quotes group words).
     *        timeout: seconds before a conversion is stopped (default 600); null waits indefinitely.
     *
     * @throws Exception If the timeout is not a positive number of seconds or null.
     */
    public function __construct(
        array $options = [],
        private readonly FileSystemHelper $helper = new FileSystemHelper(),
        private readonly ZipHandler $zipHandler = new ZipHandler()
    ) {
        $defaultOptions = [
            'calibre_path' => '/usr/bin/ebook-convert',
            'extra_args' => [],
            'timeout' => 600,
        ];

        $this->options = array_merge($defaultOptions, $options);

        if ($this->options['timeout'] !== null && $this->options['timeout'] < 1) {
            throw new Exception('CalibreAdapter timeout must be a positive number of seconds or null');
        }
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
        // No shell is involved: every argument reaches Calibre as it is. Output includes stderr,
        // so failures carry Calibre's message, and a conversion that hangs is stopped.
        $result = $this->helper->runProcess(
            [$calibrePath, $inputFile, $outputPath, ...$this->extraArguments()],
            $this->options['timeout']
        );

        if ($result['exitCode'] !== 0) {
            throw new Exception('Calibre conversion failed: ' . trim($result['output']));
        }

        if (! $this->helper->fileExists($outputPath) || $this->helper->fileSize($outputPath) === 0) {
            throw new Exception('Calibre conversion failed');
        }
    }

    /**
     * @return list<string>
     */
    private function extraArguments(): array
    {
        $extraArgs = $this->options['extra_args'];

        if (! is_string($extraArgs)) {
            return $extraArgs;
        }

        if ($extraArgs === '') {
            return [];
        }

        trigger_error(
            'Passing CalibreAdapter extra_args as a string is deprecated; pass a list of arguments.',
            E_USER_DEPRECATED
        );

        // Split at spaces; "double" or 'single' quotes group words. Nothing else is interpreted.
        preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $extraArgs, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        return array_map(static fn (array $match): string => $match[1] ?? $match[2] ?? $match[3] ?? '', $matches);
    }
}
