<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Test\Support\EpubBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Simple test for BUG-02: setCoverImage should update cover page references
 */
final class CoverImageSimpleTest extends TestCase
{
    public function testSetCoverImageUpdatesCoverPage(): void
    {
        // Create an EPUB with a cover page that references a cover image
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:test</dc:identifier>
    <dc:title>Test Book</dc:title>
    <dc:language>en</dc:language>
    <meta name="cover" content="cover-img"/>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="cover-page" href="cover.xhtml" media-type="application/xhtml+xml"/>
    <item id="cover-img" href="images/cover.png" media-type="image/png" properties="cover-image"/>
  </manifest>
  <spine>
    <itemref idref="cover-page"/>
  </spine>
</package>
XML;

        $coverXhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><img src="images/cover.png" alt="Cover"/></body></html>';

        $path = sys_get_temp_dir() . '/test_cover_simple.epub';
        $builder = (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', '<html><body></body></html>')
            ->withFile('EPUB/cover.xhtml', $coverXhtml)
            ->withFile('EPUB/images/cover.png', base64_decode(EpubBuilder::PNG))
            ->buildEpub($path);

        try {
            $epub = EpubFile::open($path);

            // Set a new cover image - this should update the cover page's img tag
            $newPng = base64_decode(EpubBuilder::PNG);
            $epub->setCoverImage($newPng, 'image/png', 'EPUB/images/new-cover.png', false);

            // Get the cover page content
            $content = $epub->getContentManager()->getContent('EPUB/cover.xhtml');

            // The img tag should now point to the new cover image
            $this->assertStringContainsString('src="images/new-cover.png"', $content);

            // The new cover image should be in the manifest
            $newCover = $epub->getManifest()->findByPath('EPUB/images/new-cover.png');
            $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $newCover);

            // The old cover image should still be in the manifest (because deletePrevious=false)
            $oldCover = $epub->getManifest()->get('cover-img');
            $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $oldCover);
            $this->assertSame('EPUB/images/cover.png', $oldCover->path);

            $epub->close();
        } finally {
            @unlink($path);
        }
    }

    public function testSetCoverImageDeletesOldWhenNoReferences(): void
    {
        // Create an EPUB with a cover page that references a cover image
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:test</dc:identifier>
    <dc:title>Test Book</dc:title>
    <dc:language>en</dc:language>
    <meta name="cover" content="cover-img"/>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="cover-page" href="cover.xhtml" media-type="application/xhtml+xml"/>
    <item id="cover-img" href="images/cover.png" media-type="image/png" properties="cover-image"/>
  </manifest>
  <spine>
    <itemref idref="cover-page"/>
  </spine>
</package>
XML;

        $coverXhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><img src="images/cover.png" alt="Cover"/></body></html>';

        $path = sys_get_temp_dir() . '/test_cover_delete.epub';
        $builder = (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', '<html><body></body></html>')
            ->withFile('EPUB/cover.xhtml', $coverXhtml)
            ->withFile('EPUB/images/cover.png', base64_decode(EpubBuilder::PNG))
            ->buildEpub($path);

        try {
            $epub = EpubFile::open($path);

            // Set a new cover image with deletePrevious=true
            // This should update the cover page and delete the old cover
            $newPng = base64_decode(EpubBuilder::PNG);
            $epub->setCoverImage($newPng, 'image/png', 'EPUB/images/new-cover.png', true);

            // Get the cover page content
            $content = $epub->getContentManager()->getContent('EPUB/cover.xhtml');

            // The img tag should now point to the new cover image
            $this->assertStringContainsString('src="images/new-cover.png"', $content);

            // The new cover image should be in the manifest
            $newCover = $epub->getManifest()->findByPath('EPUB/images/new-cover.png');
            $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $newCover);

            // The old cover image should be deleted from the manifest
            $oldCover = $epub->getManifest()->get('cover-img');
            $this->assertNull($oldCover, 'Old cover should be deleted when no references remain');

            $epub->close();
        } finally {
            @unlink($path);
        }
    }

    public function testSetCoverImageKeepsOldWhenStillReferenced(): void
    {
        // Create an EPUB with a cover page AND a chapter that both reference the cover image
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:test</dc:identifier>
    <dc:title>Test Book</dc:title>
    <dc:language>en</dc:language>
    <meta name="cover" content="cover-img"/>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="cover-page" href="cover.xhtml" media-type="application/xhtml+xml"/>
    <item id="cover-img" href="images/cover.png" media-type="image/png" properties="cover-image"/>
    <item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine>
    <itemref idref="cover-page"/>
    <itemref idref="chapter"/>
  </spine>
</package>
XML;

        $coverXhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><img src="images/cover.png" alt="Cover"/></body></html>';
        $chapterXhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><p><img src="images/cover.png" alt="Cover"/></p></body></html>';

        $path = sys_get_temp_dir() . '/test_cover_keep.epub';
        $builder = (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', '<html><body></body></html>')
            ->withFile('EPUB/cover.xhtml', $coverXhtml)
            ->withFile('EPUB/chapter.xhtml', $chapterXhtml)
            ->withFile('EPUB/images/cover.png', base64_decode(EpubBuilder::PNG))
            ->buildEpub($path);

        try {
            $epub = EpubFile::open($path);

            // Set a new cover image with deletePrevious=true
            // This should update the cover page but NOT delete the old cover
            // because the chapter still references it
            $newPng = base64_decode(EpubBuilder::PNG);
            $epub->setCoverImage($newPng, 'image/png', 'EPUB/images/new-cover.png', true);

            // Get the cover page content
            $coverContent = $epub->getContentManager()->getContent('EPUB/cover.xhtml');

            // The cover page img tag should now point to the new cover image
            $this->assertStringContainsString('src="images/new-cover.png"', $coverContent);

            // The chapter should still have the old reference (it's not a cover page)
            $chapterContent = $epub->getContentManager()->getContent('EPUB/chapter.xhtml');
            $this->assertStringContainsString('src="images/cover.png"', $chapterContent);

            // The new cover image should be in the manifest
            $newCover = $epub->getManifest()->findByPath('EPUB/images/new-cover.png');
            $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $newCover);

            // The old cover image should still be in the manifest (because chapter references it)
            $oldCover = $epub->getManifest()->get('cover-img');
            $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $oldCover, 'Old cover should NOT be deleted if still referenced');
            $this->assertSame('EPUB/images/cover.png', $oldCover->path);

            $epub->close();
        } finally {
            @unlink($path);
        }
    }

    public function testSetCoverImageKeepsOldWhenUnspinedContentReferencesIt(): void
    {
        $opf = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:test</dc:identifier>
    <dc:title>Test Book</dc:title>
    <dc:language>en</dc:language>
    <meta name="cover" content="cover-img"/>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="cover-page" href="cover.xhtml" media-type="application/xhtml+xml"/>
    <item id="cover-img" href="images/cover.png" media-type="image/png" properties="cover-image"/>
    <item id="appendix" href="appendix.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine>
    <itemref idref="cover-page"/>
  </spine>
</package>
XML;

        $path = sys_get_temp_dir() . '/test_cover_unspined.epub';
        (new EpubBuilder())
            ->withFile('mimetype', 'application/epub+zip')
            ->withContainer('EPUB/package.opf')
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/nav.xhtml', '<html><body></body></html>')
            ->withFile('EPUB/cover.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body><img src="images/cover.png"/></body></html>')
            ->withFile('EPUB/appendix.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body><img src="images/cover.png"/></body></html>')
            ->withFile('EPUB/images/cover.png', base64_decode(EpubBuilder::PNG))
            ->buildEpub($path);

        $epub = null;
        try {
            $epub = EpubFile::open($path);
            $epub->setCoverImage((string) base64_decode(EpubBuilder::PNG), 'image/png', 'EPUB/images/new-cover.png', true);

            $this->assertInstanceOf(\PhpEpub\ManifestItem::class, $epub->getManifest()->get('cover-img'));
            $this->assertFileExists($epub->getTempDir() . '/EPUB/images/cover.png');
            $this->assertStringContainsString(
                'src="images/cover.png"',
                $epub->getContentManager()->getContent('EPUB/appendix.xhtml')
            );
        } finally {
            $epub?->close();
            @unlink($path);
        }
    }
}
