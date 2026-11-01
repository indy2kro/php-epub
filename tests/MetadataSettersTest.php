<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PhpEpub\Identifier;
use PhpEpub\Metadata;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\XmlParser;
use PHPUnit\Framework\TestCase;

/**
 * The typed setters: setIsbn(), setSubtitle() and setRights().
 */
final class MetadataSettersTest extends TestCase
{
    private string $tmpDir;

    private string $opfPath;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'metadata-setters';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }

        $this->opfPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'package.opf';
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testSetIsbnAddsAnOnixTypedIdentifierInEpub3(): void
    {
        $metadata = $this->load('3.0');

        $metadata->setIsbn('978-0-306-40615-7');
        $metadata = $this->reload($metadata);

        $this->assertSame('9780306406157', $metadata->getIsbn());
        $this->assertSame('urn:uuid:00000000-0000-0000-0000-000000000000', $metadata->getUniqueIdentifier());
        $this->assertEquals(new Identifier('9780306406157', 'ISBN'), $metadata->getTypedIdentifiers()[1]);
        $opf = (string) file_get_contents($this->opfPath);
        $this->assertMatchesRegularExpression('#<(?:opf:)?meta refines="\#[^"]+" property="identifier-type" scheme="onix:codelist5">15</(?:opf:)?meta>#', $opf);
    }

    public function testSetIsbnAddsAnOpfSchemeInEpub2(): void
    {
        $metadata = $this->load('2.0');

        $metadata->setIsbn('0-8044-2957-X');
        $metadata = $this->reload($metadata);

        $this->assertSame('080442957X', $metadata->getIsbn());
        $this->assertStringContainsString('opf:scheme="ISBN">080442957X</dc:identifier>', (string) file_get_contents($this->opfPath));
    }

    public function testSetIsbnReplacesTheExistingIsbn(): void
    {
        $metadata = $this->load('3.0', '<dc:identifier id="isbn">urn:isbn:9780306406157</dc:identifier>');

        $metadata->setIsbn('0-306-40615-2');
        $metadata = $this->reload($metadata);

        $this->assertSame('0306406152', $metadata->getIsbn());
        $this->assertCount(2, $metadata->getIdentifiers());
        $opf = (string) file_get_contents($this->opfPath);
        $this->assertSame(1, substr_count($opf, 'identifier-type'));
        // An ISBN-10 is ONIX code 02; 15 is for an ISBN-13.
        $this->assertMatchesRegularExpression('#property="identifier-type" scheme="onix:codelist5">02</#', $opf);
    }

    public function testSetIsbnRejectsAnInvalidIsbn(): void
    {
        $metadata = $this->load('3.0');

        $this->expectException(Exception::class);
        $metadata->setIsbn('9780306406158');
    }

    public function testSetIsbnRejectsA13DigitValueOutsideTheIsbnPrefixes(): void
    {
        $metadata = $this->load('3.0');

        // The check digit is valid, but 000 is not an ISBN prefix (978, 979).
        $this->expectException(Exception::class);
        $metadata->setIsbn('0000000000000');
    }

    public function testSetSubtitleReplacesAndRemovesEverySubtitle(): void
    {
        $twoSubtitles = '<dc:title id="m">Book</dc:title><dc:title id="s1">One</dc:title><dc:title id="s2">Two</dc:title>'
            . '<meta refines="#s1" property="title-type">subtitle</meta><meta refines="#s2" property="title-type">subtitle</meta>';

        $metadata = $this->load('3.0', '', $twoSubtitles);
        $metadata->setSubtitle('New');
        $this->assertSame(['Book', 'New'], $metadata->getTitles());
        $this->assertSame('New', $metadata->getSubtitle());

        $metadata = $this->load('3.0', '', $twoSubtitles);
        $metadata->setSubtitle(null);
        $metadata = $this->reload($metadata);
        $this->assertNull($metadata->getSubtitle());
        $this->assertSame(['Book'], $metadata->getTitles());
    }

    public function testSetSubtitleDoesNotRemoveTheOnlyTitle(): void
    {
        $metadata = $this->load('3.0', '', '<dc:title id="s">Only</dc:title><meta refines="#s" property="title-type">subtitle</meta>');

        $this->expectException(Exception::class);
        $metadata->setSubtitle(null);
    }

    public function testSetSubtitleAddsARefinedTitleAndMarksTheMainTitle(): void
    {
        $metadata = $this->load('3.0');
        $this->assertNull($metadata->getSubtitle());

        $metadata->setSubtitle('A Novel');
        $metadata = $this->reload($metadata);

        $this->assertSame('A Novel', $metadata->getSubtitle());
        $this->assertSame('Book', $metadata->getTitle());
        $this->assertSame(['Book', 'A Novel'], $metadata->getTitles());
        $opf = (string) file_get_contents($this->opfPath);
        $this->assertStringContainsString('property="title-type">main</', $opf);
        $this->assertStringContainsString('property="title-type">subtitle</', $opf);
    }

    public function testSetSubtitleReplacesAndRemovesTheSubtitle(): void
    {
        $metadata = $this->load('3.0');
        $metadata->setSubtitle('First');
        $metadata->setSubtitle('Second');

        $this->assertSame('Second', $metadata->getSubtitle());
        $this->assertSame(['Book', 'Second'], $metadata->getTitles());

        $metadata->setSubtitle(null);
        $metadata = $this->reload($metadata);

        $this->assertNull($metadata->getSubtitle());
        $this->assertSame(['Book'], $metadata->getTitles());
        $this->assertStringNotContainsString('subtitle', (string) file_get_contents($this->opfPath));
    }

    public function testSetSubtitleKeepsAMainTitleThatIsNotTheFirst(): void
    {
        $metadata = $this->load('3.0', '', '<dc:title id="c">Collection</dc:title><dc:title id="m">Book</dc:title>'
            . '<meta refines="#c" property="title-type">collection</meta><meta refines="#m" property="title-type">main</meta>');

        $metadata->setSubtitle('A Novel');

        $this->assertSame('Book', $metadata->getTitle());
        $this->assertSame('A Novel', $metadata->getSubtitle());
        $this->assertSame(1, substr_count($this->reloadXml($metadata), '">main</'));
    }

    public function testSetSubtitleNeedsEpub3(): void
    {
        $metadata = $this->load('2.0');

        $this->expectException(Exception::class);
        $metadata->setSubtitle('A Novel');
    }

    public function testSetSubtitleRejectsAnEmptySubtitle(): void
    {
        $metadata = $this->load('3.0');

        $this->expectException(Exception::class);
        $metadata->setSubtitle(' ');
    }

    public function testRights(): void
    {
        $metadata = $this->load('3.0');
        $this->assertNull($metadata->getRights());

        $metadata->setRights('© 2026 The Author');
        $metadata = $this->reload($metadata);
        $this->assertSame('© 2026 The Author', $metadata->getRights());

        $metadata->setRights('CC BY 4.0');
        $this->assertSame(['CC BY 4.0'], $metadata->getDublinCoreValues('rights'));

        $metadata->setRights(null);
        $this->assertNull($this->reload($metadata)->getRights());
    }

    /**
     * A book whose unique identifier is a UUID, followed by $identifiers.
     */
    private function load(string $version, string $identifiers = '', string $titles = '<dc:title>Book</dc:title>'): Metadata
    {
        file_put_contents(
            $this->opfPath,
            '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="' . $version . '" unique-identifier="uid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">'
            . '<dc:identifier id="uid">urn:uuid:00000000-0000-0000-0000-000000000000</dc:identifier>'
            . "{$identifiers}{$titles}<dc:language>en</dc:language></metadata><manifest/><spine/></package>"
        );

        return new Metadata((new XmlParser())->parse($this->opfPath), $this->opfPath);
    }

    private function reload(Metadata $metadata): Metadata
    {
        $metadata->save();

        return new Metadata((new XmlParser())->parse($this->opfPath), $this->opfPath);
    }

    private function reloadXml(Metadata $metadata): string
    {
        $metadata->save();

        return (string) file_get_contents($this->opfPath);
    }
}
