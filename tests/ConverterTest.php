<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Converter;
use PhpEpub\Converters\ConverterInterface;
use PhpEpub\Exception;
use PHPUnit\Framework\TestCase;

final class ConverterTest extends TestCase
{
    public function testDispatchesToTheAdapterForTheFormat(): void
    {
        $pdf = $this->createMock(ConverterInterface::class);
        $pdf->expects($this->once())->method('convert')->with(__DIR__, '/out/book.pdf');
        $mobi = $this->createMock(ConverterInterface::class);
        $mobi->expects($this->never())->method('convert');

        (new Converter(__DIR__, ['pdf' => $pdf, 'mobi' => $mobi]))->convert('pdf', '/out/book.pdf');
    }

    public function testUnsupportedFormatThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Conversion format not supported: docx');

        (new Converter(__DIR__, []))->convert('docx', '/out/book.docx');
    }

    public function testMissingDirectoryThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('EPUB directory does not exist');

        new Converter(__DIR__ . '/missing', []);
    }
}
