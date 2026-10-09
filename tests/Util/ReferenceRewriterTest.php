<?php

declare(strict_types=1);

namespace PhpEpub\Test\Util;

use PhpEpub\Util\ContentDocumentProperties;
use PhpEpub\Util\ReferenceRewriter;
use PHPUnit\Framework\TestCase;

final class ReferenceRewriterTest extends TestCase
{
    public function testReferencesThatAreNotLocalFilesAreLeftAlone(): void
    {
        $rewriter = new ReferenceRewriter();
        $css = 'a { background: url(), url(""), url(#frag), url(data:image/png;base64,AAAA), url(https://example.com/x.png), url(?q=1), url(../../../outside.png) }';

        // The document moves to another directory, so every reference that could be a file is a candidate.
        $this->assertNull($rewriter->rewriteCss($css, 'EPUB/text/a.css', 'EPUB/a.css', 'EPUB/x.png', 'EPUB/y.png'));
    }

    public function testQuoteStylesAndFragmentsSurvive(): void
    {
        $rewriter = new ReferenceRewriter();
        $css = "a { background: url('x.png?v=1#f') } b { background: url( \"x.png\" ) } @import 'x.png';";

        $this->assertSame(
            "a { background: url('y/x.png?v=1#f') } b { background: url(\"y/x.png\") } @import 'y/x.png';",
            $rewriter->rewriteCss($css, 'EPUB/a.css', 'EPUB/a.css', 'EPUB/x.png', 'EPUB/y/x.png')
        );
    }

    public function testStyleElementsWithCdataAreRewritten(): void
    {
        $xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><head><style><![CDATA[p { background: url(x.png) }]]></style></head><body/></html>';

        $rewritten = (new ReferenceRewriter())->rewriteXml($xhtml, 'EPUB/a.xhtml', 'EPUB/a.xhtml', 'EPUB/x.png', 'EPUB/y.png');

        $this->assertNotNull($rewritten);
        $this->assertStringContainsString('url(y.png)', $rewritten);
    }

    public function testSrcsetCandidatesAreRewritten(): void
    {
        $xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><img srcset="x.png 1x, x.png?v=1 2x, other.png 3x" src="other.png"/></body></html>';

        $rewritten = (new ReferenceRewriter())->rewriteXml($xhtml, 'EPUB/a.xhtml', 'EPUB/a.xhtml', 'EPUB/x.png', 'EPUB/y/x.jpg');

        $this->assertNotNull($rewritten);
        $this->assertStringContainsString('srcset="y/x.jpg 1x, y/x.jpg?v=1 2x, other.png 3x"', $rewritten);
    }

    public function testMalformedAndUnaffectedDocumentsAreNotRewritten(): void
    {
        $rewriter = new ReferenceRewriter();

        $this->assertNull($rewriter->rewriteXml('<html>', 'EPUB/a.xhtml', 'EPUB/a.xhtml', 'EPUB/x.png', 'EPUB/y.png'));
        $this->assertNull($rewriter->rewriteXml('<html><a href="z.png"/></html>', 'EPUB/a.xhtml', 'EPUB/a.xhtml', 'EPUB/x.png', 'EPUB/y.png'));
    }

    public function testDetectingPropertiesOfMalformedXhtmlReturnsNull(): void
    {
        $this->assertNull(ContentDocumentProperties::detect('<html>'));
    }
}
