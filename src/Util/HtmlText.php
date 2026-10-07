<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Plain text of an XHTML or HTML document: what a reader sees, for search indexes, word counts and previews.
 *
 * @internal
 */
final class HtmlText
{
    /**
     * Elements whose content is not text for the reader.
     */
    private const array SKIPPED = ['script', 'style', 'template', 'head', 'title'];

    /**
     * Elements that start and end a line.
     */
    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'body', 'caption', 'dd', 'details', 'dialog', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup',
        'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'tbody', 'tfoot', 'thead', 'tr', 'ul',
    ];

    /**
     * The text of the document body: one line per paragraph, heading, list item, table row and line break,
     * with white space collapsed and entities decoded; the content of scripts and styles is left out.
     * The document is read as HTML, so it need not be well-formed, and in its declared encoding (UTF-8 or UTF-16).
     */
    public static function extract(string $content): string
    {
        $document = new DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            // The leading declaration makes the HTML parser read the bytes as UTF-8.
            $document->loadHTML('<?xml encoding="UTF-8">' . TextEncoding::toUtf8($content), LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        $body = $document->getElementsByTagName('body')->item(0);
        $lines = [];
        foreach (explode("\n", $body instanceof DOMElement ? self::collect($body) : '') as $line) {
            $line = trim((string) preg_replace('/ {2,}/', ' ', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The text below a node, with a line break where a block or a <br> starts and ends.
     */
    private static function collect(DOMNode $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                // Source line breaks are white space; only blocks and <br> break lines.
                $text .= (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $child->data);
            } elseif ($child instanceof DOMElement) {
                $name = strtolower($child->localName ?? '');
                if (in_array($name, self::SKIPPED, true)) {
                    continue;
                }

                $text .= match (true) {
                    $name === 'br' => "\n",
                    in_array($name, self::BLOCKS, true) => "\n" . self::collect($child) . "\n",
                    in_array($name, ['td', 'th'], true) => ' ' . self::collect($child) . ' ',
                    default => self::collect($child),
                };
            }
        }

        return $text;
    }
}
