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

    public function testADocumentThatIsNotWellFormedIsLeftAlone(): void
    {
        $this->assertNull((new LinkRemover())->remove('a.xhtml', '<html><body><a href="b.xhtml">broken', ['b.xhtml' => true]));
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
