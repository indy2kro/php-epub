<?php

declare(strict_types=1);

namespace PhpEpub\Test\Util;

use Iterator;
use PhpEpub\Util\TextEncoding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextEncodingTest extends TestCase
{
    #[DataProvider('documentProvider')]
    public function testConvertsToUtf8(string $content, string $expected): void
    {
        $this->assertSame($expected, TextEncoding::toUtf8($content));
    }

    public static function documentProvider(): Iterator
    {
        $html = '<html><body><p>Привет, мир</p></body></html>';
        $declaration = '<?xml version="1.0" encoding="%s"?>';

        yield 'plain UTF-8' => [$html, $html];
        yield 'UTF-8 with a byte order mark' => ["\xEF\xBB\xBF" . $html, $html];
        yield 'UTF-8 declared' => [sprintf($declaration, 'utf-8') . $html, sprintf($declaration, 'utf-8') . $html];
        yield 'no declaration at all' => ['text', 'text'];
        yield 'UTF-16LE with a byte order mark' => ["\xFF\xFE" . mb_convert_encoding($html, 'UTF-16LE', 'UTF-8'), $html];
        yield 'UTF-16BE with a byte order mark' => ["\xFE\xFF" . mb_convert_encoding($html, 'UTF-16BE', 'UTF-8'), $html];
        yield 'UTF-16LE without a byte order mark' => [mb_convert_encoding($html, 'UTF-16LE', 'UTF-8'), $html];
        yield 'UTF-16BE without a byte order mark' => [mb_convert_encoding($html, 'UTF-16BE', 'UTF-8'), $html];
        yield 'UTF-16 with its declaration' => [
            "\xFF\xFE" . mb_convert_encoding(sprintf($declaration, 'UTF-16') . "\n" . $html, 'UTF-16LE', 'UTF-8'),
            $html,
        ];
        yield 'declared ISO-8859-1' => [sprintf($declaration, 'ISO-8859-1') . "<p>caf\xE9</p>", '<p>café</p>'];
        yield 'declared alias only iconv knows' => [sprintf($declaration, 'latin1') . "<p>caf\xE9</p>", '<p>café</p>'];
        yield 'declared unknown encoding' => [sprintf($declaration, 'x-unknown') . '<p>text</p>', sprintf($declaration, 'x-unknown') . '<p>text</p>'];
    }
}
