<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use ReflectionClass;
use TCPDF;

/**
 * TCPDF that reads local files only from the given directories (the book being converted and
 * its temporary files) and from TCPDF's own packages (fonts).
 *
 * TCPDF 7 otherwise reads from the system temp dir, the working directory and the script
 * directory, which may hold other books, and nowhere else, so a book extracted elsewhere would
 * lose its images. TCPDF 6 has no allowlist; there this class changes nothing.
 *
 * @internal Created by TCPDFAdapter::newPdf().
 */
class ConfinedTcpdf extends TCPDF
{
    /**
     * @param list<string> $readableDirectories
     */
    public function __construct(string $orientation, string $format, private readonly array $readableDirectories = [])
    {
        parent::__construct($orientation, 'mm', $format);
    }

    /**
     * Overrides TCPDF 7's allowlist of local paths.
     *
     * @return array<int, string>
     */
    protected function fileAllowedPaths(): array
    {
        // The TCPDF package and its sibling tecnickcom packages (font data).
        $tcpdfDirectory = dirname((string) (new ReflectionClass(TCPDF::class))->getFileName());
        $candidates = [...$this->readableDirectories, $tcpdfDirectory, dirname($tcpdfDirectory)];
        if (defined('K_PATH_FONTS') && is_string(K_PATH_FONTS)) {
            $candidates[] = K_PATH_FONTS;
        }

        $paths = [];
        foreach ($candidates as $candidate) {
            $real = $candidate === '' ? false : realpath($candidate);
            if ($real !== false) {
                $paths[] = $real;
            }
        }

        return array_values(array_unique($paths));
    }
}
