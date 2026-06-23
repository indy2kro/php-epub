#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generates TCPDF 7.x core font definition JSON files.
 *
 * The 14 standard PDF core fonts (Standard 14) do not ship as pre-built
 * JSON font definition files with Composer packages. They must be
 * generated from Adobe Core14 AFM files.
 *
 * This script downloads those AFM files from the tc-font-mirror GitHub
 * repository and uses the tc-lib-pdf-font Import class to convert them.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$rootDir = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
chdir($rootDir);

$autoload = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run composer install first.\n");
    exit(1);
}

require $autoload;

$outputDir = str_replace('\\', '/', $rootDir) . '/vendor/tecnickcom/tc-lib-pdf-font/target/fonts';
if (!str_ends_with($outputDir, '/')) {
    $outputDir .= '/';
}
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}
if (!is_writable($outputDir)) {
    fwrite(STDERR, "Output directory is not writable: {$outputDir}\n");
    exit(1);
}

echo "Output directory: {$outputDir}\n";

$afmFiles = [
    'Courier.afm',
    'Courier-Bold.afm',
    'Courier-BoldOblique.afm',
    'Courier-Oblique.afm',
    'Helvetica.afm',
    'Helvetica-Bold.afm',
    'Helvetica-BoldOblique.afm',
    'Helvetica-Oblique.afm',
    'Symbol.afm',
    'Times.afm',
    'Times-Bold.afm',
    'Times-BoldItalic.afm',
    'Times-Italic.afm',
    'ZapfDingbats.afm',
];

$baseUrl = 'https://raw.githubusercontent.com/tecnickcom/tc-font-mirror/main/core/';

$tmpDir = $rootDir . '/var/tmp/fonts';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0777, true);
}

$success = 0;
$errors = 0;

foreach ($afmFiles as $afmFile) {
    $url = $baseUrl . $afmFile;
    $tmpPath = $tmpDir . '/' . $afmFile;

    echo "Downloading {$afmFile}... ";

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'user_agent' => 'php-epub-font-generator/1.0',
            'follow_location' => 1,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $content = @file_get_contents($url, false, $context);

    if ($content === false) {
        echo "ERROR: could not download.\n";
        $errors++;
        continue;
    }

    file_put_contents($tmpPath, $content);
    echo "OK.\n";

    $basename = basename($afmFile, '.afm');
    $encoding = '';
    $type = '';

    if ($basename === 'Symbol') {
        $encoding = 'symbol';
    } elseif ($basename !== 'ZapfDingbats') {
        $encoding = 'cp1252';
    }

    try {
        $import = new \Com\Tecnick\Pdf\Font\Import(
            $tmpPath,
            $outputDir,
            $type,
            $encoding,
        );
        $fontName = $import->getFontName();
        echo "  -> Generated: {$fontName}.json\n";
        $success++;
    } catch (\Exception $e) {
        echo "  -> ERROR: {$e->getMessage()}\n";
        $errors++;
    }
}

// Clean up temp directory
$removeDir = function (string $dir) use (&$removeDir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
    }
    @rmdir($dir);
};
$removeDir($rootDir . '/var/tmp');

echo "\nDone. {$success} font(s) generated, {$errors} error(s).\n";

if ($errors > 0) {
    exit(1);
}
