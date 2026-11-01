<?php

declare(strict_types=1);

namespace PhpEpub\Test\Util;

use PhpEpub\Util\LinkRemover;
use PHPUnit\Framework\TestCase;

final class LinkRemoverTest extends TestCase
{
    public function testLinksToRemovedFilesBecomeTextAndOthersStay(): void
    {
        $content = '<html xmlns="http://www.w3.org/1999/xhtml"><head><link rel="next" href="two.xhtml"/><link rel="stylesheet" href="s.css"/></head>'
            . '<body><p><a href="two.xhtml#x">to <b>two</b></a> <a href="one.xhtml">to one</a> <a href="https://example.com/two.xhtml">out</a></p></body></html>';

        $result = (new LinkRemover())->remove('EPUB/one.xhtml', $content, ['EPUB/two.xhtml' => true]);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('two.xhtml#x', $result);
        $this->assertStringContainsString('to <b>two</b>', $result);
        $this->assertStringNotContainsString('rel="next"', $result);
        $this->assertStringContainsString('<a href="one.xhtml">to one</a>', $result);
        $this->assertStringContainsString('href="https://example.com/two.xhtml"', $result);
        $this->assertStringContainsString('href="s.css"', $result);
    }

    public function testNothingChangesWithoutLinksToRemovedFiles(): void
    {
        $remover = new LinkRemover();

        $this->assertNull($remover->remove('a.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body><p>No links</p></body></html>', ['b.xhtml' => true]));
        $this->assertNull($remover->remove('a.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body><p>b.xhtml is only text</p></body></html>', ['b.xhtml' => true]));
        $this->assertNull($remover->remove('a.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body><a href="c.xhtml">b.xhtml</a></body></html>', ['b.xhtml' => true]));
    }

    public function testADocumentWithABaseUrlIsLeftAlone(): void
    {
        $remover = new LinkRemover();

        $this->assertNull($remover->remove('EPUB/a.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><head><base href="../images/"/></head>'
            . '<body><img src="b.png" alt=""/></body></html>', ['EPUB/b.png' => true]));
        $this->assertNull($remover->remove('EPUB/a.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body>'
            . '<div xml:base="../images/"><img src="b.png" alt=""/></div></body></html>', ['EPUB/b.png' => true]));
    }

    public function testFindsReferencesInAUtf16Document(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-16"?><html xmlns="http://www.w3.org/1999/xhtml"><body><p><a href="b.xhtml">B</a></p></body></html>';
        $utf16 = "\xFF\xFE" . (string) mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');

        $result = (new LinkRemover())->remove('a.xhtml', $utf16, ['b.xhtml' => true]);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('href', (string) mb_convert_encoding($result, 'UTF-8', 'UTF-16'));
    }

    public function testADocumentThatIsNotWellFormedIsLeftAlone(): void
    {
        $this->assertNull((new LinkRemover())->remove('a.xhtml', '<html><body><a href="b.xhtml">broken', ['b.xhtml' => true]));
    }

    public function testRemovingXlinkAnchorPreservesItsSvgChildren(): void
    {
        $content = '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<body><svg xmlns="http://www.w3.org/2000/svg"><a xlink:href="image.svg">'
            . '<text>Label</text><rect width="1" height="1"/></a></svg></body></html>';

        $result = (new LinkRemover())->remove('EPUB/chapter.xhtml', $content, ['EPUB/image.svg' => true]);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('xlink:href="image.svg"', $result);
        $this->assertStringContainsString('<text>Label</text>', $result);
        $this->assertStringContainsString('<rect', $result);
    }

    public function testRemovingReferencesFromMediaAttributes(): void
    {
        $content = '<html xmlns="http://www.w3.org/1999/xhtml"><body>'
            . '<object data="object.svg"/><video poster="poster.png"/>'
            . '<img srcset="removed.png 1x, kept.png 2x"/><link imagesrcset="removed.png 1x, kept.png 2x"/>'
            . '</body></html>';

        $result = (new LinkRemover())->remove(
            'EPUB/chapter.xhtml',
            $content,
            ['EPUB/removed.png' => true, 'EPUB/object.svg' => true, 'EPUB/poster.png' => true]
        );

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('object.svg', $result);
        $this->assertStringNotContainsString('poster.png', $result);
        $this->assertStringNotContainsString('removed.png', $result);
        $this->assertStringContainsString('kept.png 2x', $result);
    }

    public function testTargetResolvesFileReferencesOnly(): void
    {
        $remover = new LinkRemover();

        $this->assertSame('EPUB/text/b.xhtml', $remover->target('b.xhtml#frag', 'EPUB/text/a.xhtml'));
        $this->assertSame('EPUB/b.xhtml', $remover->target('../b.xhtml?q=1', 'EPUB/text/a.xhtml'));
        $this->assertSame('b.xhtml', $remover->target('b.xhtml', 'a.xhtml'));
        $this->assertNull($remover->target('', 'a.xhtml'));
        $this->assertNull($remover->target('#only-a-fragment', 'a.xhtml'));
        $this->assertNull($remover->target('?query-only', 'a.xhtml'));
        $this->assertNull($remover->target('//example.com/x', 'a.xhtml'));
        $this->assertNull($remover->target('mailto:someone@example.com', 'a.xhtml'));
        $this->assertNull($remover->target('../../outside.xhtml', 'a.xhtml'));
    }
}
