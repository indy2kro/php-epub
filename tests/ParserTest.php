<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PhpEpub\Parser;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\XmlParser;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    private Parser $parser;
    private string $fixturesDir;
    private string $tmpDir;
    private FileSystemHelper $fileSystemHelper;

    protected function setUp(): void
    {
        $this->fileSystemHelper = new FileSystemHelper();
        $this->fixturesDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures';
        $this->tmpDir = $this->fixturesDir . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'temp_epub';

        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }

        $this->parser = new Parser(new XmlParser());
    }

    protected function tearDown(): void
    {
        $this->fileSystemHelper->deleteDirectory($this->tmpDir);
    }

    public function testParseValidEpub(): void
    {
        $directory = $this->copyFixtureToTmp('valid_epub');

        $opfPath = $this->parser->parse($directory);

        $this->assertSame('EPUB/package.opf', $opfPath);
    }

    public function testParseMissingMimetypeThrowsException(): void
    {
        $directory = $this->copyFixtureToTmp('valid_epub');
        unlink($directory . '/mimetype');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing mimetype file:');

        $this->parser->parse($directory);
    }

    public function testParseInvalidMimetypeContentThrowsException(): void
    {
        $directory = $this->copyFixtureToTmp('valid_epub');
        file_put_contents($directory . '/mimetype', 'invalid-mimetype');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid mimetype content:');

        $this->parser->parse($directory);
    }

    public function testExtractOpfPathMissingRootfileThrowsException(): void
    {
        $directory = $this->copyFixtureToTmp('valid_epub');
        unlink($directory . '/META-INF/container.xml');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('XML file not found:');

        $this->parser->parse($directory);
    }

    public function testValidateOpfMissingManifestThrowsException(): void
    {
        $directory = $this->copyFixtureToTmp('valid_epub');
        $opfPath = $directory . '/EPUB/package.opf';
        $opfContent = file_get_contents($opfPath);
        $this->assertNotFalse($opfContent);
        file_put_contents($opfPath, str_replace('<manifest>', '', $opfContent));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to load XML file:');

        $this->parser->parse($directory);
    }

    private function copyFixtureToTmp(string $fixtureName): string
    {
        $sourceDir = $this->fixturesDir . '/' . $fixtureName;
        $destDir = $this->tmpDir . '/' . $fixtureName;

        $this->copyDirectory($sourceDir, $destDir);

        return $destDir;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0777, true);

        $items = scandir($source);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $srcPath = $source . '/' . $item;
            $destPath = $destination . '/' . $item;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $destPath);
            } else {
                copy($srcPath, $destPath);
            }
        }
    }
}
