<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\Exception;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\ZipHandler;

class CalibreAdapter implements ConverterInterface
{
    /**
     * Where the Calibre installers put ebook-convert, tried when it is not on the PATH.
     */
    private const array INSTALL_LOCATIONS = [
        '/usr/bin/ebook-convert',
        '/usr/local/bin/ebook-convert',
        '/opt/calibre/ebook-convert',
        '/Applications/calibre.app/Contents/MacOS/ebook-convert',
        'C:\\Program Files\\Calibre2\\ebook-convert.exe',
        'C:\\Program Files (x86)\\Calibre2\\ebook-convert.exe',
    ];

    /**
     * @var array{calibre_path: string|null, extra_args: string|list<string>, timeout: int|null}
     */
    private array $options;

    /**
     * CalibreAdapter constructor.
     *
     * @param array{calibre_path?: string|null, extra_args?: string|list<string>, timeout?: int|null} $options
     *        calibre_path: path to ebook-convert; by default it is looked up on the PATH, then in
     *        the usual Linux, macOS and Windows install locations.
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
            'calibre_path' => null,
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
        $calibrePath = $this->calibrePath();

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
            [$calibrePath, self::pathArgument($inputFile), self::pathArgument($outputPath), ...$this->extraArguments()],
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
     * A path as an ebook-convert argument: a relative path starting with "-" gets "./" (".\" on
     * Windows) in front, so Calibre never reads it as an option.
     */
    private static function pathArgument(string $path): string
    {
        return str_starts_with($path, '-') ? '.' . DIRECTORY_SEPARATOR . $path : $path;
    }

    /**
     * The configured ebook-convert, or else the one on the PATH or in a usual install location.
     *
     * @throws Exception If it cannot be found.
     */
    private function calibrePath(): string
    {
        $configured = $this->options['calibre_path'];
        if ($configured !== null) {
            if (! $this->helper->fileExists($configured)) {
                throw new Exception('Calibre tool not found at path: ' . $configured);
            }

            return $configured;
        }

        $found = $this->helper->findExecutable('ebook-convert');
        if ($found !== null) {
            return $found;
        }

        foreach (self::INSTALL_LOCATIONS as $location) {
            if ($this->helper->fileExists($location)) {
                return $location;
            }
        }

        throw new Exception(
            "Calibre's ebook-convert was not found on the PATH or in the usual install locations; set the calibre_path option."
        );
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
