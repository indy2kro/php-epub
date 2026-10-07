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
 * repository (TLS verified, and checked against pinned SHA-256 hashes) and
 * uses the tc-lib-pdf-font Import class to convert them into the directory
 * where Composer installed tecnickcom/tc-lib-pdf-font, which is where TCPDF
 * looks for them.
 *
 * It runs automatically after "composer install/update" in this repository.
 * Projects that use php-epub as a dependency run it once themselves:
 *
 *     php vendor/indy2kro/php-epub/scripts/generate-core-fonts.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// Root project: ./vendor/autoload.php. Installed as a dependency: vendor/indy2kro/php-epub/scripts -> vendor/autoload.php.
$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];

$autoload = null;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    fwrite(STDERR, "vendor/autoload.php not found. Run composer install first.\n");
    exit(1);
}

require $autoload;

if (! Composer\InstalledVersions::isInstalled('tecnickcom/tc-lib-pdf-font')) {
    echo "tecnickcom/tc-lib-pdf-font is not installed (TCPDF is optional); no fonts to generate.\n";
    exit(0);
}

$fontPackageDir = Composer\InstalledVersions::getInstallPath('tecnickcom/tc-lib-pdf-font');
$realFontPackageDir = $fontPackageDir === null ? false : realpath($fontPackageDir);
if ($realFontPackageDir === false) {
    fwrite(STDERR, "Cannot locate the tecnickcom/tc-lib-pdf-font install directory.\n");
    exit(1);
}

$outputDir = str_replace('\\', '/', $realFontPackageDir) . '/target/fonts/';
if (! is_dir($outputDir) && ! mkdir($outputDir, 0777, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, "Cannot create output directory: {$outputDir}\n");
    exit(1);
}
if (! is_writable($outputDir)) {
    fwrite(STDERR, "Output directory is not writable: {$outputDir}\n");
    exit(1);
}

echo "Output directory: {$outputDir}\n";

// SHA-256 of each AFM file in tc-font-mirror; a download that does not match is rejected.
$afmFiles = [
    'Courier.afm' => '521e0d7c7521efd4be78a5a9c5398e4c67d0771e396115b0346bc4ef74ada53d',
    'Courier-Bold.afm' => 'ad0150d4bedcc8877742bf94251fcec13e348dd599d4603f679d92027d1e6e99',
    'Courier-BoldOblique.afm' => 'cb82e69ef5f6d421e8f404fe00bb0d993425aae79725331d0ebf847e94e97e92',
    'Courier-Oblique.afm' => 'b27103b2a2ef6030c110626597e2ab47bb8279075a039ab9170facb0aa1f70e1',
    'Helvetica.afm' => 'da33f1870474c8e68bfe3e2353ff107ab6c6eea1f9836ce2aaf1e1a07b17982f',
    'Helvetica-Bold.afm' => 'b880d96baf56d0cc059f258f60b4d764ef49b555ab9db294b959c0016dee41f2',
    'Helvetica-BoldOblique.afm' => '69984a35ca26973a39f261cf83e0d367ea2e6517c590b0b030d4d4a219d9c269',
    'Helvetica-Oblique.afm' => 'b4609b71b660a392ac09df35060271a876c2ce66617dd83bf826f742bb9d9721',
    'Symbol.afm' => '3d2128a820375a10de9bc8bf6cfb15ded482c01ca0f95cc0b3277f37ec8bde66',
    'Times.afm' => '768e1cabea085d489a63da3e80b96bc5abf0ec98d3073c9b4d6ba76e7bccba64',
    'Times-Bold.afm' => 'b4a000ed85cb22c6cdd985aa0fd3f6f78ed5079b7c6860dc4f0234e0d0e3c522',
    'Times-BoldItalic.afm' => '93c4744ba955215de02c4aae0b777133442ada2f7ab5a2af30a040b792b3c55d',
    'Times-Italic.afm' => 'ed37fa2e6a67b5b17dfd47f36fc7e90df32891a4408860dbc8d4cbbe9959e242',
    'ZapfDingbats.afm' => 'a32565c90afd1b57a7008fc567b78d95cf1c22adff5e086094d666d88b039859',
];

// DejaVu Sans (regular, bold, oblique, bold oblique) is TCPDFAdapter's default font: unlike the core
// fonts it covers Latin, Greek, Cyrillic, Hebrew and Arabic. Same mirror, same SHA-256 check.
$ttfFiles = [
    'DejaVuSans.ttf' => '7da195a74c55bef988d0d48f9508bd5d849425c1770dba5d7bfc6ce9ed848954',
    'DejaVuSans-Bold.ttf' => 'e6476c1b80502924294eed40894c5b18e06c181444ca953e5334262df9c27724',
    'DejaVuSans-Oblique.ttf' => '4af75fa16ee6d3ad43e1ecec41862c24954af26a55c6bb1ebb27bd486a50f5f4',
    'DejaVuSans-BoldOblique.ttf' => 'eb436dca0c2594b73d8b603b892e374fdfd8d885d25ffb4f18df4c4c0b49e50f',
];

// File name => [SHA-256, URL of its directory in the mirror].
$fontFiles = [];
foreach ($afmFiles as $file => $hash) {
    $fontFiles[$file] = [$hash, 'https://raw.githubusercontent.com/tecnickcom/tc-font-mirror/main/core/'];
}
foreach ($ttfFiles as $file => $hash) {
    $fontFiles[$file] = [$hash, 'https://raw.githubusercontent.com/tecnickcom/tc-font-mirror/main/dejavu/ttf/'];
}

$tmpDir = sys_get_temp_dir() . '/php-epub-fonts-' . bin2hex(random_bytes(8));
if (! mkdir($tmpDir, 0700, true)) {
    fwrite(STDERR, "Cannot create temporary directory: {$tmpDir}\n");
    exit(1);
}

// TLS certificates are verified (PHP's default); never disable verify_peer here.
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 30,
        'user_agent' => 'php-epub-font-generator/1.0',
        'follow_location' => 1,
    ],
]);

$success = 0;
$errors = 0;

foreach ($fontFiles as $afmFile => [$expectedHash, $baseUrl]) {
    $tmpPath = $tmpDir . '/' . $afmFile;

    echo "Downloading {$afmFile}... ";

    $content = @file_get_contents($baseUrl . $afmFile, false, $context);

    if ($content === false) {
        $reason = error_get_last()['message'] ?? 'unknown error';
        echo "ERROR: could not download ({$reason}).\n";
        $errors++;
        continue;
    }

    if (! hash_equals($expectedHash, hash('sha256', $content))) {
        echo "ERROR: checksum mismatch, refusing to use it.\n";
        $errors++;
        continue;
    }

    file_put_contents($tmpPath, $content);
    echo "OK.\n";

    $basename = pathinfo($afmFile, PATHINFO_FILENAME);
    $encoding = '';
    $type = '';

    if (isset($ttfFiles[$afmFile])) {
        $type = 'TrueTypeUnicode';
    } elseif ($basename === 'Symbol') {
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
        $msg = $e->getMessage();
        if (str_contains($msg, 'already imported')) {
            echo "  -> SKIP: {$msg}\n";
        } else {
            echo "  -> ERROR: {$msg}\n";
            $errors++;
        }
    }
}

// Clean up the temporary directory
foreach (glob($tmpDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($tmpDir);

echo "\nDone. {$success} font(s) generated, {$errors} error(s).\n";

if ($errors > 0) {
    exit(1);
}
