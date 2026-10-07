<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use DOMDocument;
use DOMNode;

/**
 * Turns HTML-ish markup (named entities such as &nbsp;, void tags such as <br>, unclosed
 * tags) into well-formed XHTML.
 */
final class XhtmlFragment
{
    /**
     * Parses $html as an HTML fragment with libxml (the network is never touched) and serializes
     * it as XHTML: named entities become characters, void elements self-close and open tags are closed.
     */
    public static function fromHtml(string $html): string
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();
            // The XML declaration tells the HTML parser the input is UTF-8; the explicit body keeps
            // leading <script> or <style> elements out of the head.
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET);

            $xhtml = '';
            $body = $document->getElementsByTagName('body')->item(0);
            foreach ($body instanceof DOMNode ? $body->childNodes : [] as $child) {
                $xhtml .= $document->saveXML($child);
            }

            return $xhtml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
