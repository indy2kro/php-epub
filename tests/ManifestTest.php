<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PhpEpub\InvalidEpubException;
use PhpEpub\Manifest;
use PhpEpub\Test\Support\EpubBuilder;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

final class ManifestTest extends TestCase
{
    public function testGetItemsResolvesPathsRelativeToTheBookRoot(): void
    {
        $manifest = $this->manifest(
            '<item id="css" href="../styles/main.css" media-type="text/css"/>'
            . '<item id="cover" href="images/my%20cover.jpg" media-type="image/jpeg" properties="cover-image"/>'
        );

        $items = $manifest->getItems();

        $this->assertSame(['chapter', 'css', 'cover'], array_map(static fn ($item): string => $item->id, $items));
        $this->assertSame('EPUB/chapter.xhtml', $items[0]->path);
        $this->assertSame('styles/main.css', $items[1]->path);
        $this->assertSame('EPUB/images/my cover.jpg', $items[2]->path);
        $this->assertSame('cover-image', $items[2]->properties);
        $this->assertSame('image/jpeg', $items[2]->mediaType);
    }

    public function testGetAndFindByPath(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('chapter.xhtml', $manifest->get('chapter')?->href);
        $this->assertNull($manifest->get('missing'));
        $this->assertSame('chapter', $manifest->findByPath('EPUB/./chapter.xhtml')?->id);
        $this->assertNull($manifest->findByPath('EPUB/other.xhtml'));
    }

    public function testPathAndHrefConversion(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('text/a%20b.xhtml', $manifest->pathToHref('EPUB/text/a b.xhtml'));
        $this->assertSame('../fonts/f.woff2', $manifest->pathToHref('fonts/f.woff2'));
        $this->assertSame('EPUB/text/a b.xhtml', $manifest->hrefToPath('text/a%20b.xhtml#section-2'));
    }

    public function testRemoteResourcesHaveNoLocalPath(): void
    {
        $manifest = $this->manifest('<item id="audio" href="https://example.com/a.mp3" media-type="audio/mpeg"/>');

        $this->assertSame('', $manifest->get('audio')?->path);
        $this->assertCount(2, $manifest->getItems());
    }

    public function testItemsPointingOutsideTheBookDoNotBreakTheManifest(): void
    {
        $manifest = $this->manifest('<item id="escape" href="../../outside.xhtml" media-type="application/xhtml+xml"/>');

        $items = $manifest->getItems();

        $this->assertCount(2, $items);
        // Like a remote resource: listed, but without a file in the book.
        $this->assertSame('', $manifest->get('escape')?->path);
        $this->assertSame('chapter', $manifest->findByPath('EPUB/chapter.xhtml')?->id);
        $this->assertSame('new-xhtml', $manifest->add('EPUB/new.xhtml')->id);
    }

    public function testHrefToPathRejectsHrefsOutsideTheBook(): void
    {
        $this->expectException(InvalidEpubException::class);

        $this->manifest()->hrefToPath('../../outside.xhtml');
    }

    public function testPathConversionWithOpfAtTheBookRoot(): void
    {
        $manifest = new Manifest(new SimpleXMLElement(EpubBuilder::opf()), 'package.opf');

        $this->assertSame('text/a.xhtml', $manifest->pathToHref('text/a.xhtml'));
        $this->assertSame('chapter.xhtml', $manifest->get('chapter')?->path);
    }

    public function testAddCreatesAnItemWithGuessedMediaTypeAndUniqueId(): void
    {
        $manifest = $this->manifest('<item id="new-chapter-xhtml" href="taken.xhtml" media-type="application/xhtml+xml"/>');

        $item = $manifest->add('EPUB/text/New Chapter.xhtml');
        $image = $manifest->add('EPUB/images/plate.PNG');
        $unknown = $manifest->add('EPUB/data/blob.bin');
        $explicit = $manifest->add('EPUB/nav.xhtml', 'application/xhtml+xml', 'nav');

        $this->assertSame('new-chapter-xhtml-2', $item->id);
        $this->assertSame('text/New%20Chapter.xhtml', $item->href);
        $this->assertSame('application/xhtml+xml', $item->mediaType);
        $this->assertSame('image/png', $image->mediaType);
        $this->assertSame('application/octet-stream', $unknown->mediaType);
        $this->assertSame('nav', $explicit->id);
        $this->assertEquals($item, $manifest->findByPath('EPUB/text/New Chapter.xhtml'));
        $this->assertTrue($manifest->isModified());
    }

    public function testIdsAreUniqueAcrossThePackageNotJustTheManifest(): void
    {
        $opf = EpubBuilder::opf(metadata: '<dc:creator id="cover-jpg">Ann</dc:creator>');
        $manifest = new Manifest(new SimpleXMLElement($opf), 'EPUB/package.opf');

        $this->assertSame('cover-jpg-2', $manifest->add('EPUB/cover.jpg')->id);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('already in use');

        // "uid" belongs to the dc:identifier.
        $manifest->add('EPUB/other.xhtml', null, 'uid');
    }

    public function testGeneratedIdsStartWithALetter(): void
    {
        $this->assertSame('item-01-xhtml', $this->manifest()->add('EPUB/01.xhtml')->id);
    }

    public function testPackageWithoutManifestThrows(): void
    {
        $this->expectException(InvalidEpubException::class);
        $this->expectExceptionMessage('Missing manifest in OPF file');

        new Manifest(new SimpleXMLElement('<package xmlns="http://www.idpf.org/2007/opf"><metadata/></package>'), 'package.opf');
    }

    public function testAddRejectsDuplicatePathsAndIds(): void
    {
        $manifest = $this->manifest();

        try {
            $manifest->add('EPUB/chapter.xhtml');
            $this->fail('Expected an exception for a path already in the manifest.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('already in the manifest', $exception->getMessage());
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('already in use');

        $manifest->add('EPUB/other.xhtml', null, 'chapter');
    }

    public function testValuesThatAreNotValidXmlTextAreRejected(): void
    {
        $manifest = $this->manifest();
        $attempts = [
            'media type' => static fn () => $manifest->add('EPUB/a.xhtml', "application/xhtml+xml\x01"),
            'id' => static fn () => $manifest->add('EPUB/b.xhtml', null, "caf\xE9"),
            'property' => static fn () => $manifest->addProperty('chapter', "nav\x0B"),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("Expected an exception for an invalid {$label}.");
            } catch (Exception $exception) {
                $this->assertStringContainsString('not valid XML text', $exception->getMessage(), $label);
            }
        }

        $this->assertCount(1, $manifest->getItems());
        $this->assertFalse($manifest->isModified());
    }

    public function testRemoveClearsReferencesToTheItem(): void
    {
        $opf = '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="uid">urn:x</dc:identifier>'
            . '<meta name="cover" content="cover"/><meta name="calibre:series" content="Kept"/>'
            . '<meta refines="#cover" property="alt-script">Cover</meta><meta refines="#uid" property="identifier-type">kept</meta>'
            . '</metadata><manifest>'
            . '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
            . '<item id="cover" href="images/cover.jpg" media-type="image/jpeg"/>'
            . '<item id="cover-page" href="cover.xhtml" media-type="application/xhtml+xml"/>'
            . '<item id="alt" href="images/cover.webp" media-type="image/webp" fallback="cover"/>'
            . '<item id="audio-page" href="a.xhtml" media-type="application/xhtml+xml" media-overlay="cover"/>'
            . '</manifest><spine toc="ncx"><itemref idref="cover-page"/></spine>'
            . '<guide><reference type="cover" href="images/cover.jpg"/><reference type="text" href="cover.xhtml#start"/></guide>'
            . '</package>';
        $xml = new SimpleXMLElement($opf);
        $manifest = new Manifest($xml, 'EPUB/package.opf');

        $manifest->remove('cover');
        $manifest->remove('ncx');
        $manifest->remove('cover-page');

        $saved = (string) $xml->asXML();
        $this->assertStringNotContainsString('name="cover"', $saved);
        $this->assertStringNotContainsString('refines="#cover"', $saved);
        $this->assertStringNotContainsString('fallback=', $saved);
        $this->assertStringNotContainsString('media-overlay=', $saved);
        $this->assertStringNotContainsString('toc=', $saved);
        $this->assertStringNotContainsString('<reference', $saved);
        // Unrelated metadata and spine entries stay; spine itemrefs are Spine's job.
        $this->assertStringContainsString('content="Kept"', $saved);
        $this->assertStringContainsString('refines="#uid"', $saved);
        $this->assertStringContainsString('<itemref idref="cover-page"/>', $saved);
    }

    public function testRemove(): void
    {
        $manifest = $this->manifest();

        $manifest->remove('chapter');

        $this->assertSame([], $manifest->getItems());
        $this->assertTrue($manifest->isModified());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No manifest item with id "chapter"');

        $manifest->remove('chapter');
    }

    public function testAddAndRemoveProperties(): void
    {
        $manifest = $this->manifest('<item id="img" href="i.png" media-type="image/png" properties="svg"/>');

        $manifest->addProperty('img', 'cover-image');
        $manifest->addProperty('img', 'cover-image');
        $this->assertSame('svg cover-image', $manifest->get('img')?->properties);

        $manifest->removeProperty('img', 'svg');
        $manifest->removeProperty('img', 'cover-image');
        $manifest->removeProperty('img', 'not-there');
        $this->assertSame('', $manifest->get('img')?->properties);
        $this->assertTrue($manifest->isModified());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No manifest item with id "missing"');

        $manifest->addProperty('missing', 'nav');
    }

    private function manifest(string $extraItems = ''): Manifest
    {
        return new Manifest(new SimpleXMLElement(EpubBuilder::opf($extraItems)), 'EPUB/package.opf');
    }
}
