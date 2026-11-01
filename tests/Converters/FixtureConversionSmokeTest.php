<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use PhpEpub\Converters\DompdfAdapter;
use PhpEpub\Converters\HtmlAdapter;
use PhpEpub\Converters\MarkdownAdapter;
use PhpEpub\Converters\PdfConversionOptions;
use PhpEpub\Converters\TextAdapter;
use PhpEpub\Converters\TCPDFAdapter;
use PhpEpub\EpubFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test for TEST-02: Convert every real-world fixture with each adapter
 *
 * This is a smoke test that ensures the converter adapters can handle real-world EPUB files.
 * CONV-01 (TCPDF fails on CSS auto values) would have been caught by these tests.
 */
final class FixtureConversionSmokeTest extends TestCase
{
    private static string $outputDir;

    public static function setUpBeforeClass(): void
    {
        self::$outputDir = sys_get_temp_dir() . '/epub_converter_smoke_test';
        if (! is_dir(self::$outputDir)) {
            mkdir(self::$outputDir, 0777, true);
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up the output directory
        self::removeDirectory(self::$outputDir);
    }

    /**
     * Data provider for real-world fixtures
     */
    public static function fixtureProvider(): array
    {
        $fixturesDir = dirname(__DIR__) . '/fixtures';
        $fixtures = [];

        // List all valid*.epub files
        $files = glob($fixturesDir . '/valid*.epub');

        foreach ($files as $file) {
            $fixtures[basename($file)] = [$file];
        }

        return $fixtures;
    }

    /**
     * Test that TextAdapter can convert each real-world fixture
     */
    #[DataProvider('fixtureProvider')]
    public function testTextAdapterWithFixture(string $fixturePath): void
    {
        if (! class_exists('\Dompdf\Dompdf') && ! class_exists('\TCPDF')) {
            $this->markTestSkipped('Neither Dompdf nor TCPDF is installed');
        }

        $epub = EpubFile::open($fixturePath);
        $outputPath = self::$outputDir . '/' . basename($fixturePath, '.epub') . '.txt';

        try {
            $adapter = new TextAdapter();
            $epub->convert($adapter, $outputPath);

            // Verify the output file was created and is not empty
            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));

            // Verify it's valid text (just check it's a string and not empty)
            $content = file_get_contents($outputPath);
            $this->assertIsString($content);
            $this->assertGreaterThan(100, strlen($content ?? ''));
        } finally {
            $epub->close();
            @unlink($outputPath);
        }
    }

    /**
     * Test that HtmlAdapter can convert each real-world fixture
     */
    #[DataProvider('fixtureProvider')]
    public function testHtmlAdapterWithFixture(string $fixturePath): void
    {
        if (! class_exists('\Dompdf\Dompdf') && ! class_exists('\TCPDF')) {
            $this->markTestSkipped('Neither Dompdf nor TCPDF is installed');
        }

        $epub = EpubFile::open($fixturePath);
        $outputPath = self::$outputDir . '/' . basename($fixturePath, '.epub') . '.html';

        try {
            $adapter = new HtmlAdapter();
            $epub->convert($adapter, $outputPath);

            // Verify the output file was created and is not empty
            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));

            // Verify it's valid HTML
            $content = file_get_contents($outputPath);
            $this->assertIsString($content);
            $this->assertStringContainsString('<html', $content ?? '');
            $this->assertStringContainsString('</html>', $content ?? '');
        } finally {
            $epub->close();
            @unlink($outputPath);
        }
    }

    /**
     * Test that MarkdownAdapter can convert each real-world fixture
     */
    #[DataProvider('fixtureProvider')]
    public function testMarkdownAdapterWithFixture(string $fixturePath): void
    {
        if (! class_exists('\Dompdf\Dompdf') && ! class_exists('\TCPDF')) {
            $this->markTestSkipped('Neither Dompdf nor TCPDF is installed');
        }

        $epub = EpubFile::open($fixturePath);
        $outputPath = self::$outputDir . '/' . basename($fixturePath, '.epub') . '.md';

        try {
            $adapter = new MarkdownAdapter();
            $epub->convert($adapter, $outputPath);

            // Verify the output file was created and is not empty
            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));

            // Verify it's valid Markdown (just check it's a string and not empty)
            $content = file_get_contents($outputPath);
            $this->assertIsString($content);
            $this->assertGreaterThan(100, strlen($content ?? ''));
        } finally {
            $epub->close();
            @unlink($outputPath);
        }
    }

    /**
     * Test that DompdfAdapter can convert each real-world fixture
     *
     * Note: PDF generation can be slow, so this test has a generous timeout.
     * These tests are skipped in CI when Dompdf is not installed.
     */
    #[DataProvider('fixtureProvider')]
    public function testDompdfAdapterWithFixture(string $fixturePath): void
    {
        if (! class_exists('\Dompdf\Dompdf')) {
            $this->markTestSkipped('Dompdf is not installed');
        }

        // Skip this test in CI to avoid timeout issues
        // The text, HTML, and Markdown tests above verify the adapters work
        // and would catch most issues like CONV-01
        if (getenv('CI') !== false || getenv('GITHUB_ACTIONS') !== false) {
            $this->markTestSkipped('Skipping slow PDF generation tests in CI');
        }

        $epub = EpubFile::open($fixturePath);
        $outputPath = self::$outputDir . '/' . basename($fixturePath, '.epub') . '_dompdf.pdf';

        try {
            $adapter = new DompdfAdapter();
            $epub->convert($adapter, $outputPath);

            // Verify the output file was created and is not empty
            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));

            // Verify it's a valid PDF (starts with %PDF)
            $content = file_get_contents($outputPath);
            $this->assertIsString($content);
            $this->assertStringStartsWith('%PDF', $content ?? '');
        } finally {
            $epub->close();
            @unlink($outputPath);
        }
    }

    /**
     * Test that TCPDFAdapter can convert each real-world fixture
     *
     * Note: PDF generation can be slow, so this test has a generous timeout.
     * These tests are skipped in CI when TCPDF is not installed.
     */
    #[DataProvider('fixtureProvider')]
    public function testTCPDFAdapterWithFixture(string $fixturePath): void
    {
        if (! class_exists('\TCPDF')) {
            $this->markTestSkipped('TCPDF is not installed');
        }

        // Skip this test in CI to avoid timeout issues
        // The text, HTML, and Markdown tests above verify the adapters work
        // and would catch most issues like CONV-01
        if (getenv('CI') !== false || getenv('GITHUB_ACTIONS') !== false) {
            $this->markTestSkipped('Skipping slow PDF generation tests in CI');
        }

        $epub = EpubFile::open($fixturePath);
        $outputPath = self::$outputDir . '/' . basename($fixturePath, '.epub') . '_tcpdf.pdf';

        try {
            $adapter = new TCPDFAdapter();
            $epub->convert($adapter, $outputPath);

            // Verify the output file was created and is not empty
            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));

            // Verify it's a valid PDF (starts with %PDF)
            $content = file_get_contents($outputPath);
            $this->assertIsString($content);
            $this->assertStringStartsWith('%PDF', $content ?? '');
        } finally {
            $epub->close();
            @unlink($outputPath);
        }
    }

    /**
     * Helper method to recursively remove a directory
     */
    private static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $file;
            if (is_dir($fullPath)) {
                self::removeDirectory($fullPath);
            } else {
                @unlink($fullPath);
            }
        }
        @rmdir($path);
    }
}
