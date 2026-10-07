<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Identifier;
use PhpEpub\Metadata;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\XmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Identifier schemes, as EPUB 2 and EPUB 3 books and identifier prefixes express them.
 */
final class IdentifierTest extends TestCase
{
    private string $tmpDir;

    private string $opfPath;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'identifiers';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }

        $this->opfPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'package.opf';
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    #[DataProvider('identifiers')]
    public function testDetectsTheScheme(string $element, string $value, ?string $scheme): void
    {
        $identifiers = $this->load($element)->getTypedIdentifiers();

        $this->assertEquals(new Identifier($value, $scheme), $identifiers[1]);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function identifiers(): iterable
    {
        yield 'EPUB 2 opf:scheme' => ['<dc:identifier opf:scheme="isbn">0-306-40615-2</dc:identifier>', '0-306-40615-2', 'ISBN'];
        yield 'EPUB 2 other scheme' => ['<dc:identifier opf:scheme="calibre">1234</dc:identifier>', '1234', 'CALIBRE'];
        yield 'EPUB 3 ONIX ISBN-13' => ['<dc:identifier id="i">9780306406157</dc:identifier><meta refines="#i" property="identifier-type" scheme="onix:codelist5">15</meta>', '9780306406157', 'ISBN'];
        yield 'EPUB 3 ONIX DOI' => ['<dc:identifier id="i">10.1000/182</dc:identifier><meta refines="#i" property="identifier-type" scheme="onix:codelist5">06</meta>', '10.1000/182', 'DOI'];
        yield 'EPUB 3 named type' => ['<dc:identifier id="i">X1</dc:identifier><meta refines="#i" property="identifier-type">asin</meta>', 'X1', 'ASIN'];
        yield 'urn:isbn prefix' => ['<dc:identifier> urn:isbn:978-0-306-40615-7 </dc:identifier>', 'urn:isbn:978-0-306-40615-7', 'ISBN'];
        yield 'urn:uuid prefix' => ['<dc:identifier>urn:uuid:3f1e2a4c-5b6d-4e7f-8a9b-0c1d2e3f4a5b</dc:identifier>', 'urn:uuid:3f1e2a4c-5b6d-4e7f-8a9b-0c1d2e3f4a5b', 'UUID'];
        yield 'doi prefix' => ['<dc:identifier>doi:10.1000/182</dc:identifier>', 'doi:10.1000/182', 'DOI'];
        yield 'bare ISBN-13' => ['<dc:identifier>978-0-306-40615-7</dc:identifier>', '978-0-306-40615-7', 'ISBN'];
        yield 'bare ISBN-10 with X' => ['<dc:identifier>0-8044-2957-X</dc:identifier>', '0-8044-2957-X', 'ISBN'];
        yield 'number with a wrong check digit' => ['<dc:identifier>9780306406158</dc:identifier>', '9780306406158', null];
        yield 'unknown' => ['<dc:identifier>book-42</dc:identifier>', 'book-42', null];
    }

    public function testGetIsbnReturnsTheFirstIsbnWithoutPrefixOrSeparators(): void
    {
        $metadata = $this->load('<dc:identifier>book-42</dc:identifier><dc:identifier>urn:isbn:978-0-306-40615-7</dc:identifier>');

        $this->assertSame('9780306406157', $metadata->getIsbn());
    }

    public function testGetIsbnIsNullWithoutAnIsbn(): void
    {
        $this->assertNull($this->load('<dc:identifier>book-42</dc:identifier>')->getIsbn());
    }

    /**
     * A book whose unique identifier is a UUID, followed by $identifiers.
     */
    private function load(string $identifiers): Metadata
    {
        file_put_contents(
            $this->opfPath,
            '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">'
            . '<dc:identifier id="uid">urn:uuid:00000000-0000-0000-0000-000000000000</dc:identifier>'
            . "{$identifiers}<dc:title>Book</dc:title><dc:language>en</dc:language></metadata><manifest/><spine/></package>"
        );

        return new Metadata((new XmlParser())->parse($this->opfPath), $this->opfPath);
    }
}
