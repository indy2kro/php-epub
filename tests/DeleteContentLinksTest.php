<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Test\Support\EpubBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Test for BUG-03: deleteContent should remove links to deleted file from other documents
 */
final class DeleteContentLinksTest extends TestCase
{
    public function testDeleteContentRemovesLinksFromOtherDocuments(): void
    {
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:test</dc:identifier>
    <dc:title>Test Book</dc:title>
    <dc:language>en</dc:language>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="chapter1" href="text/chapter1.xhtml" media-type="application/xhtml+xml"/>
    <item id="chapter2" href="text/chapter2.xhtml" media-type="application/xhtml+xml"/>
    <item id="chapter3" href="text/chapter3.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine>
    <itemref idref="chapter1"/>
    <itemref idref="chapter2"/>
    <itemref idref="chapter3"/>
  </spine>
</package>
XML;

        $navXhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><nav epub:type="toc"><ol><li><a href="text/chapter1.xhtml">Chapter 1</a></li><li><a href="text/chapter2.xhtml">Chapter 2</a></li><li><a href="text/chapter3.xhtml">Chapter 3</a></li></ol></nav></body></html>';
        // References are relative to the chapter1.xhtml location (EPUB/text/)
        $chapter1Xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 1</h1><p>See <a href="chapter2.xhtml">Chapter 2</a> and <a href="chapter3.xhtml">Chapter 3</a>.</p></body></html>';
        $chapter2Xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 2</h1><p>See <a href="chapter1.xhtml">Chapter 1</a> and <a href="chapter3.xhtml">Chapter 3</a>.</p></body></html>';
        $chapter3Xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 3</h1><p>See <a href="chapter1.xhtml">Chapter 1</a> and <a href="chapter2.xhtml">Chapter 2</a>.</p></body></html>';

        $path = sys_get_temp_dir() . '/test_delete_links.epub';
        $builder = (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', $navXhtml)
            ->withFile('EPUB/text/chapter1.xhtml', $chapter1Xhtml)
            ->withFile('EPUB/text/chapter2.xhtml', $chapter2Xhtml)
            ->withFile('EPUB/text/chapter3.xhtml', $chapter3Xhtml)
            ->buildEpub($path);

        try {
            $epub = EpubFile::open($path);

            // Delete chapter2
            $epub->getContentManager()->deleteContent('EPUB/text/chapter2.xhtml');

            // Check that chapter1 no longer has a link to chapter2
            $chapter1Content = $epub->getContentManager()->getContent('EPUB/text/chapter1.xhtml');
            $this->assertStringNotContainsString('href="chapter2.xhtml"', $chapter1Content);
            // But it should still have a link to chapter3
            $this->assertStringContainsString('href="chapter3.xhtml"', $chapter1Content);

            // Check that chapter3 no longer has a link to chapter2
            $chapter3Content = $epub->getContentManager()->getContent('EPUB/text/chapter3.xhtml');
            $this->assertStringNotContainsString('href="chapter2.xhtml"', $chapter3Content);
            // But it should still have a link to chapter1
            $this->assertStringContainsString('href="chapter1.xhtml"', $chapter3Content);

            // Check that chapter2 is removed from the manifest
            $chapter2Item = $epub->getManifest()->findByPath('EPUB/text/chapter2.xhtml');
            $this->assertNull($chapter2Item);

            // Check that chapter2 is removed from the spine
            $spine = $epub->getSpine()->get();
            $this->assertNotContains('chapter2', $spine);

            $epub->close();
        } finally {
            @unlink($path);
        }
    }

    public function testDeleteContentRemovesImgSrcLinks(): void
    {
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:test2</dc:identifier>
    <dc:title>Test Book 2</dc:title>
    <dc:language>en</dc:language>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="chapter1" href="text/chapter1.xhtml" media-type="application/xhtml+xml"/>
    <item id="image1" href="images/image1.png" media-type="image/png"/>
  </manifest>
  <spine>
    <itemref idref="chapter1"/>
  </spine>
</package>
XML;

        $navXhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><nav epub:type="toc"><ol><li><a href="text/chapter1.xhtml">Chapter 1</a></li></ol></nav></body></html>';
        $chapter1Xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 1</h1><img src="../images/image1.png" alt="Image 1"/></body></html>';

        $path = sys_get_temp_dir() . '/test_delete_img_links.epub';
        $builder = (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', $navXhtml)
            ->withFile('EPUB/text/chapter1.xhtml', $chapter1Xhtml)
            ->withFile('EPUB/images/image1.png', 'dummy image content')
            ->buildEpub($path);

        try {
            $epub = EpubFile::open($path);

            // Delete the image
            $epub->getContentManager()->deleteContent('EPUB/images/image1.png');

            // Check that chapter1 no longer has a reference to the image
            $chapter1Content = $epub->getContentManager()->getContent('EPUB/text/chapter1.xhtml');
            $this->assertStringNotContainsString('src="../images/image1.png"', $chapter1Content);

            // The img tag itself should be removed or the src attribute cleared
            $this->assertStringNotContainsString('image1.png', $chapter1Content);

            $epub->close();
        } finally {
            @unlink($path);
        }
    }
}
