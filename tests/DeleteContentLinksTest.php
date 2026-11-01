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
        (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', $navXhtml)
            ->withFile('EPUB/text/chapter1.xhtml', $chapter1Xhtml)
            ->withFile('EPUB/text/chapter2.xhtml', $chapter2Xhtml)
            ->withFile('EPUB/text/chapter3.xhtml', $chapter3Xhtml)
            ->buildEpub($path);

        $epub = null;
        try {
            $epub = EpubFile::open($path);

            // Delete chapter2
            $epub->getContentManager()->deleteContent('EPUB/text/chapter2.xhtml');

            // Check that chapter1 no longer has a link to chapter2
            $chapter1Content = $epub->getContentManager()->getContent('EPUB/text/chapter1.xhtml');
            $this->assertStringNotContainsString('href="chapter2.xhtml"', $chapter1Content);
            // But it should still have a link to chapter3
            $this->assertStringContainsString('href="chapter3.xhtml"', $chapter1Content);
            // And the link text should remain (the <a> is unwrapped)
            $this->assertStringContainsString('Chapter 2', $chapter1Content);
            $this->assertStringContainsString('Chapter 3', $chapter1Content);

            // Check that chapter3 no longer has a link to chapter2
            $chapter3Content = $epub->getContentManager()->getContent('EPUB/text/chapter3.xhtml');
            $this->assertStringNotContainsString('href="chapter2.xhtml"', $chapter3Content);
            // But it should still have a link to chapter1
            $this->assertStringContainsString('href="chapter1.xhtml"', $chapter3Content);
            // And the link text should remain
            $this->assertStringContainsString('Chapter 1', $chapter3Content);
            $this->assertStringContainsString('Chapter 2', $chapter3Content);

            // Check that chapter2 is removed from the manifest
            $chapter2Item = $epub->getManifest()->findByPath('EPUB/text/chapter2.xhtml');
            $this->assertNull($chapter2Item);

            // Check that chapter2 is removed from the spine
            $spine = $epub->getSpine()->get();
            $this->assertNotContains('chapter2', $spine);
        } finally {
            $epub?->close();
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

        $navXhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><nav epub:type="toc"><ol><li><a href="text/chapter1.xhtml">Chapter 1</a></li></ol></nav><img src="images/image1.png"/></body></html>';
        $chapter1Xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 1</h1><img src="../images/image1.png" alt="Image 1"/></body></html>';

        $path = sys_get_temp_dir() . '/test_delete_img_links.epub';
        (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', $navXhtml)
            ->withFile('EPUB/text/chapter1.xhtml', $chapter1Xhtml)
            ->withFile('EPUB/images/image1.png', 'dummy image content')
            ->buildEpub($path);

        $epub = null;
        try {
            $epub = EpubFile::open($path);

            // Delete the image
            $epub->getContentManager()->deleteContent('EPUB/images/image1.png');

            // Check that chapter1 no longer has a reference to the image
            $chapter1Content = $epub->getContentManager()->getContent('EPUB/text/chapter1.xhtml');
            $this->assertStringNotContainsString('src="../images/image1.png"', $chapter1Content);

            // The img tag itself should be removed (and with it, the alt text)
            $this->assertStringNotContainsString('image1.png', $chapter1Content);
            $navContent = $epub->getContentManager()->getContent('EPUB/nav.xhtml');
            $this->assertStringNotContainsString('image1.png', $navContent);
        } finally {
            $epub?->close();
            @unlink($path);
        }
    }

    public function testDeleteContentRemovesDeletedCandidatesFromSrcsetAttributes(): void
    {
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:test-srcset</dc:identifier>
    <dc:title>Test Book</dc:title>
    <dc:language>en</dc:language>
  </metadata>
  <manifest>
    <item id="chapter" href="text/chapter.xhtml" media-type="application/xhtml+xml"/>
    <item id="image1" href="images/image1.png" media-type="image/png"/>
    <item id="image2" href="images/image2.png" media-type="image/png"/>
  </manifest>
  <spine>
    <itemref idref="chapter"/>
  </spine>
</package>
XML;
        $chapter = '<html xmlns="http://www.w3.org/1999/xhtml"><body><img src="../images/image2.png" srcset="../images/image1.png 1x, ../images/image2.png 2x"/><link rel="preload" href="../images/image2.png" imagesrcset="../images/image1.png 1x, ../images/image2.png 2x"/></body></html>';

        $path = sys_get_temp_dir() . '/test_delete_srcset.epub';
        (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/text/chapter.xhtml', $chapter)
            ->withFile('EPUB/images/image1.png', 'image one')
            ->withFile('EPUB/images/image2.png', 'image two')
            ->buildEpub($path);

        $epub = null;
        try {
            $epub = EpubFile::open($path);
            $epub->getContentManager()->deleteContent('EPUB/images/image1.png');

            $content = $epub->getContentManager()->getContent('EPUB/text/chapter.xhtml');
            $this->assertStringNotContainsString('image1.png', $content);
            $this->assertStringContainsString('image2.png 2x', $content);
        } finally {
            $epub?->close();
            @unlink($path);
        }
    }

    public function testDeleteContentSkipsMalformedDocument(): void
    {
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:test9</dc:identifier>
    <dc:title>Test Book 9</dc:title>
    <dc:language>en</dc:language>
  </metadata>
  <manifest>
    <item id="chapter1" href="text/chapter1.xhtml" media-type="application/xhtml+xml"/>
    <item id="chapter2" href="text/chapter2.xhtml" media-type="application/xhtml+xml"/>
    <item id="image1" href="images/image1.png" media-type="image/png"/>
  </manifest>
  <spine>
    <itemref idref="chapter1"/>
    <itemref idref="chapter2"/>
  </spine>
</package>
XML;

        $chapter1Xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 1</h1><img src="../images/image1.png" alt="Image"/></body></html>';
        // chapter2 is malformed (unclosed tag)
        $chapter2Xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 2</h1><p>Content</body></html>';

        $path = sys_get_temp_dir() . '/test_delete_malformed.epub';
        (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/text/chapter1.xhtml', $chapter1Xhtml)
            ->withFile('EPUB/text/chapter2.xhtml', $chapter2Xhtml)
            ->withFile('EPUB/images/image1.png', 'dummy image content')
            ->buildEpub($path);

        $epub = null;
        try {
            $epub = EpubFile::open($path);

            // Delete the image - should not throw even with malformed chapter2
            $epub->getContentManager()->deleteContent('EPUB/images/image1.png');

            // Check that chapter1 no longer has a reference to the image
            $chapter1Content = $epub->getContentManager()->getContent('EPUB/text/chapter1.xhtml');
            $this->assertStringNotContainsString('src="../images/image1.png"', $chapter1Content);
        } finally {
            $epub?->close();
            @unlink($path);
        }
    }

    public function testDeleteContentReportsMalformedDocumentThatReferencesDeletedFile(): void
    {
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:test-malformed-reference</dc:identifier>
    <dc:title>Test Book</dc:title>
    <dc:language>en</dc:language>
  </metadata>
  <manifest>
    <item id="chapter1" href="text/a.xhtml" media-type="application/xhtml+xml"/>
    <item id="chapter" href="text/z.xhtml" media-type="application/xhtml+xml"/>
    <item id="image" href="images/image.png" media-type="image/png"/>
  </manifest>
  <spine>
    <itemref idref="chapter1"/>
    <itemref idref="chapter"/>
  </spine>
</package>
XML;
        $chapter1 = '<html xmlns="http://www.w3.org/1999/xhtml"><body><img src="../images/image.png"/></body></html>';
        $chapter = '<html xmlns="http://www.w3.org/1999/xhtml"><body><p><img src="../images/image.png"></body></html>';

        $path = sys_get_temp_dir() . '/test_delete_malformed_reference.epub';
        (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/text/a.xhtml', $chapter1)
            ->withFile('EPUB/text/z.xhtml', $chapter)
            ->withFile('EPUB/images/image.png', 'image')
            ->buildEpub($path);

        $epub = null;
        try {
            $epub = EpubFile::open($path);
            $imagePath = $epub->getTempDir() . '/EPUB/images/image.png';

            try {
                $epub->getContentManager()->deleteContent('EPUB/images/image.png');
                $this->fail('Deleting a referenced file from a malformed document must be reported');
            } catch (\PhpEpub\XmlException) {
                $this->assertFileExists($imagePath);
                $this->assertSame($chapter1, $epub->getContentManager()->getContent('EPUB/text/a.xhtml'));
            }
        } finally {
            $epub?->close();
            @unlink($path);
        }
    }

    public function testDeleteContentRemovesLinksFromNavDocument(): void
    {
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:test10</dc:identifier>
    <dc:title>Test Book 10</dc:title>
    <dc:language>en</dc:language>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="chapter1" href="text/chapter1.xhtml" media-type="application/xhtml+xml"/>
    <item id="chapter2" href="text/chapter2.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine>
    <itemref idref="chapter1"/>
    <itemref idref="chapter2"/>
  </spine>
</package>
XML;

        $navXhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><nav epub:type="toc"><ol><li><a href="text/chapter1.xhtml">Chapter 1</a></li><li><a href="text/chapter2.xhtml">Chapter 2</a></li></ol></nav></body></html>';
        $chapter1Xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 1</h1></body></html>';
        $chapter2Xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 2</h1></body></html>';

        $path = sys_get_temp_dir() . '/test_delete_nav.epub';
        (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', $navXhtml)
            ->withFile('EPUB/text/chapter1.xhtml', $chapter1Xhtml)
            ->withFile('EPUB/text/chapter2.xhtml', $chapter2Xhtml)
            ->buildEpub($path);

        $epub = null;
        try {
            $epub = EpubFile::open($path);

            // Delete chapter2
            $epub->getContentManager()->deleteContent('EPUB/text/chapter2.xhtml');

            // Check that chapter2 is removed from manifest and spine
            $chapter2Item = $epub->getManifest()->findByPath('EPUB/text/chapter2.xhtml');
            $this->assertNull($chapter2Item);

            $spine = $epub->getSpine()->get();
            $this->assertNotContains('chapter2', $spine);

            // Check that chapter1 remains in manifest and spine
            $chapter1Item = $epub->getManifest()->findByPath('EPUB/text/chapter1.xhtml');
            $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $chapter1Item);
            $this->assertContains('chapter1', $spine);
        } finally {
            $epub?->close();
            @unlink($path);
        }
    }
}
