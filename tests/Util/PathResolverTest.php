<?php

declare(strict_types=1);

namespace PhpEpub\Test\Util;

use Iterator;
use PhpEpub\Exception;
use PhpEpub\Util\PathResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathResolverTest extends TestCase
{
    #[DataProvider('validPathProvider')]
    public function testNormalizeKeepsPathsInsideTheBook(string $path, string $expected): void
    {
        $this->assertSame($expected, (new PathResolver())->normalize($path));
    }

    public static function validPathProvider(): Iterator
    {
        yield 'plain' => ['EPUB/package.opf', 'EPUB/package.opf'];
        yield 'dot segments' => ['./EPUB/./text/../package.opf', 'EPUB/package.opf'];
        yield 'backslashes' => ['EPUB\\text\\chapter.xhtml', 'EPUB/text/chapter.xhtml'];
        yield 'repeated separators' => ['EPUB//package.opf', 'EPUB/package.opf'];
    }

    #[DataProvider('hostilePathProvider')]
    public function testNormalizeRejectsPathsOutsideTheBook(string $path): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('outside the EPUB');

        (new PathResolver())->normalize($path);
    }

    public static function hostilePathProvider(): Iterator
    {
        yield 'parent' => ['../outside.opf'];
        yield 'nested parent' => ['EPUB/../../outside.opf'];
        yield 'backslash parent' => ['EPUB\\..\\..\\outside.opf'];
        yield 'absolute unix' => ['/etc/passwd'];
        yield 'absolute windows' => ['C:\\Windows\\win.ini'];
        yield 'drive relative' => ['C:outside.opf'];
        yield 'unc' => ['\\\\server\\share\\file'];
        yield 'stream wrapper' => ['phar://book.phar/x'];
        yield 'null byte' => ["EPUB/package.opf\0.txt"];
        yield 'empty' => [''];
        yield 'only dots' => ['./.'];
    }

    public function testResolveJoinsOntoTheRootDirectory(): void
    {
        $resolved = (new PathResolver())->resolve('/books/abc/', 'EPUB/../EPUB/package.opf');

        $this->assertSame('/books/abc' . DIRECTORY_SEPARATOR . 'EPUB' . DIRECTORY_SEPARATOR . 'package.opf', $resolved);
    }
}
