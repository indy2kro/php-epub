<?php

declare(strict_types=1);

namespace PhpEpub\Test\Converters;

use PhpEpub\ConversionException;
use PhpEpub\Converters\BookImages;
use PhpEpub\Converters\HtmlAdapter;
use PhpEpub\Converters\MarkdownExport;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * Failure paths of the exporters and the book image reader.
 */
final class ExportCoverageTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'export-coverage';
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    private function fontBook(): string
    {
        return EpubBuilder::minimal()
            ->withFile('EPUB/package.opf', EpubBuilder::opf('<item id="css" href="style.css" media-type="text/css"/>'))
            ->withFile('EPUB/chapter.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>F</title><link rel="stylesheet" href="style.css"/></head><body><p>Text</p></body></html>')
            ->withFile('EPUB/style.css', '@font-face{font-family:F;src:url(f.woff2)} @font-face{font-family:G;src:url(g.ttf)} p{font-family:F}')
            ->withFile('EPUB/f.woff2', 'wOF2 not really a font')
            ->writeTo($this->tmpDir . '/book');
    }

    public function testHtmlInlinesTheBooksFontFiles(): void
    {
        $html = (new HtmlAdapter())->toString($this->fontBook());

        $this->assertStringContainsString('url("data:font/woff2;base64,' . base64_encode('wOF2 not really a font') . '")', $html);
        // A font the book does not have becomes "none".
        $this->assertStringContainsString('src:none', str_replace(' ', '', $html));
    }

    public function testMarkdownLeavesOutChaptersWithoutContent(): void
    {
        $directory = EpubBuilder::minimal()
            ->withFile('EPUB/chapter.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body><div> </div><script>x</script></body></html>')
            ->writeTo($this->tmpDir . '/empty');

        $this->assertSame('# Minimal
', (new \PhpEpub\Converters\MarkdownAdapter())->export($directory)->markdown);
    }

    public function testHtmlConvertReportsAFileItCannotWrite(): void
    {
        $this->expectException(ConversionException::class);

        (new HtmlAdapter())->convert($this->fontBook(), $this->tmpDir . '/missing/dir/book.html');
    }

    public function testBookImagesRefuseBrokenDataAndMissingFonts(): void
    {
        $directory = $this->fontBook();
        $images = new BookImages($directory);

        $this->assertNull($images->read('data:image/png;base64,@@@', 100));
        $this->assertNull($images->read('data:image/png;base64,', 100));
        $this->assertSame('data:font/woff2;base64,' . base64_encode('wOF2 not really a font'), $images->fontUri($directory . '/EPUB/f.woff2', 1000));
        $this->assertNull($images->fontUri($directory . '/EPUB/f.woff2', 5));
        $this->assertNull($images->fontUri($directory . '/EPUB/missing.woff2', 1000));
    }

    public function testMarkdownExportRefusesBadNamesAndUnwritableTargets(): void
    {
        $export = new MarkdownExport('text', ['images/a.png' => 'x']);

        foreach (['../book.md', '.hidden', 'a b.md', ''] as $name) {
            try {
                $export->writeTo($this->tmpDir . '/out', $name);
                $this->fail("Accepted the file name {$name}");
            } catch (ConversionException $exception) {
                $this->assertStringContainsString('Invalid Markdown file name', $exception->getMessage());
            }
        }

        // A directory below a file cannot be created.
        file_put_contents($this->tmpDir . '/afile', 'x');
        $this->assertWriteFails($export, $this->tmpDir . '/afile/sub', 'Failed to create the directory');

        // An image path that is a directory, and a Markdown file that is a directory.
        mkdir($this->tmpDir . '/out1/images/a.png', 0777, true);
        $this->assertWriteFails($export, $this->tmpDir . '/out1', 'Failed to write the image');
        mkdir($this->tmpDir . '/out2/book.md', 0777, true);
        $this->assertWriteFails(new MarkdownExport('text'), $this->tmpDir . '/out2', 'Failed to write the Markdown file');
    }

    private function assertWriteFails(MarkdownExport $export, string $directory, string $message): void
    {
        try {
            $export->writeTo($directory);
            $this->fail('Expected a ConversionException');
        } catch (ConversionException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
