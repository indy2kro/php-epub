<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\Metadata;
use PhpEpub\Spine;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\XmlParser;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Every test edits metadata, saves it, and reads the OPF back from disk.
 */
final class MetadataRoundTripTest extends TestCase
{
    private const string OPF_NS = 'http://www.idpf.org/2007/opf';
    private const string DC_NS = 'http://purl.org/dc/elements/1.1/';

    private string $opfPath;
    private string $epubPath;

    protected function setUp(): void
    {
        $tmp = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp';
        if (! is_dir($tmp)) {
            mkdir($tmp, 0777, true);
        }

        $this->opfPath = $tmp . DIRECTORY_SEPARATOR . 'round-trip.opf';
        $this->epubPath = $tmp . DIRECTORY_SEPARATOR . 'round-trip.epub';
    }

    protected function tearDown(): void
    {
        foreach ([$this->opfPath, $this->epubPath] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testSetIdentifiersKeepsTheUniqueIdentifierTarget(): void
    {
        $metadata = $this->load((string) file_get_contents(__DIR__ . '/fixtures/valid.opf'));

        $metadata->setIdentifiers(['urn:isbn:9780000000002', 'urn:uuid:extra']);
        $metadata->save();

        $xml = $this->reloadXml();
        $uniqueId = (string) $xml['unique-identifier'];
        $this->assertSame('uid', $uniqueId);
        $this->assertSame(['urn:isbn:9780000000002'], $this->values($xml, "//dc:identifier[@id='uid']"));
        $this->assertSame(['urn:isbn:9780000000002', 'urn:uuid:extra'], $this->reload()->getIdentifiers());
        // The identifier-type meta refined the removed "isbn-id" identifier.
        $this->assertSame([], $this->values($xml, "//opf:meta[@refines='#isbn-id']"));
    }

    public function testSetIdentifiersWithoutUniqueIdentifierAttributeReplacesInOrder(): void
    {
        $metadata = $this->load(
            '<package xmlns="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/" version="2.0">'
            . '<metadata><dc:identifier>old-1</dc:identifier><dc:identifier>old-2</dc:identifier></metadata>'
            . '<manifest/><spine/></package>'
        );

        $metadata->setIdentifiers(['new-1']);
        $metadata->save();

        $this->assertSame(['new-1'], $this->reload()->getIdentifiers());
    }

    public function testSetIdentifiersRejectsAnEmptyList(): void
    {
        $metadata = $this->load((string) file_get_contents(__DIR__ . '/fixtures/valid.opf'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('At least one identifier is required');

        $metadata->setIdentifiers([]);
    }

    public function testSetAuthorsKeepsEpub3RoleAndDropsStaleRefinements(): void
    {
        $metadata = $this->load(EpubBuilder::opf(metadata: <<<'XML'
<dc:creator id="c1">John Doe</dc:creator>
    <meta refines="#c1" property="role" scheme="marc:relators">aut</meta>
    <meta refines="#c1" property="file-as">Doe, John</meta>
    <dc:creator id="c2">Second Author</dc:creator>
    <meta refines="#c2" property="role" scheme="marc:relators">aut</meta>
XML));

        $metadata->setAuthors(['Jane Roe']);
        $metadata->save();

        $xml = $this->reloadXml();
        $this->assertSame(['Jane Roe'], $this->reload()->getAuthors());
        $this->assertSame(['c1'], $this->values($xml, '//dc:creator/@id'));
        $this->assertSame(['aut'], $this->values($xml, "//opf:meta[@refines='#c1'][@property='role']"));
        // The sort key belonged to the old name, and c2 no longer exists.
        $this->assertSame([], $this->values($xml, "//opf:meta[@refines='#c1'][@property='file-as']"));
        $this->assertSame([], $this->values($xml, "//opf:meta[@refines='#c2']"));
    }

    public function testSetAuthorsKeepsEpub2AttributesForUnchangedNames(): void
    {
        $metadata = $this->load($this->epub2Opf(
            '<dc:creator opf:role="aut" opf:file-as="Doe, John">John Doe</dc:creator>'
            . '<dc:creator opf:role="ill" opf:file-as="Smith, Ann">Ann Smith</dc:creator>'
        ));

        $metadata->setAuthors(['John Doe', 'Jane Roe']);
        $metadata->save();

        $xml = $this->reloadXml();
        $this->assertSame(['John Doe', 'Jane Roe'], $this->reload()->getAuthors());
        $this->assertSame(['aut', 'ill'], $this->values($xml, '//dc:creator/@opf:role'));
        // Only the unchanged name keeps its sort key.
        $this->assertSame(['Doe, John'], $this->values($xml, '//dc:creator/@opf:file-as'));
    }

    public function testSaveUpdatesEpub3ModifiedDateAfterAnEdit(): void
    {
        $metadata = $this->load((string) file_get_contents(__DIR__ . '/fixtures/valid.opf'));

        $metadata->setTitle('Edited');
        $metadata->save();

        $modified = $this->values($this->reloadXml(), "//opf:meta[@property='dcterms:modified']");
        $this->assertCount(1, $modified);
        $this->assertNotSame('2024-02-05T11:00:00Z', $modified[0]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $modified[0]);
    }

    public function testSaveWithoutEditsKeepsEpub3ModifiedDate(): void
    {
        $metadata = $this->load((string) file_get_contents(__DIR__ . '/fixtures/valid.opf'));

        $metadata->save();

        $this->assertSame(
            ['2024-02-05T11:00:00Z'],
            $this->values($this->reloadXml(), "//opf:meta[@property='dcterms:modified']")
        );
    }

    public function testSaveDoesNotAddModifiedDateToEpub2(): void
    {
        $metadata = $this->load($this->epub2Opf('<dc:title>Old</dc:title>'));

        $metadata->setTitle('New');
        $metadata->save();

        $this->assertSame('New', $this->reload()->getTitle());
        $this->assertSame([], $this->values($this->reloadXml(), "//opf:meta[@property='dcterms:modified']"));
    }

    public function testPrefixedPackageMetadataAndSpine(): void
    {
        $opf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<opf:package xmlns:opf="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/" version="2.0" unique-identifier="uid">
  <opf:metadata>
    <dc:identifier id="uid">urn:uuid:1</dc:identifier>
    <dc:title>Prefixed</dc:title>
  </opf:metadata>
  <opf:manifest><opf:item id="a" href="a.xhtml" media-type="application/xhtml+xml"/></opf:manifest>
  <opf:spine><opf:itemref idref="a"/></opf:spine>
</opf:package>
XML;
        $metadata = $this->load($opf);
        $this->assertSame('Prefixed', $metadata->getTitle());

        $metadata->setLanguage('fr');
        $metadata->save();

        $this->assertSame('fr', $this->reload()->getLanguage());
        $this->assertSame(['a'], (new Spine($this->reloadXml()))->get());
    }

    public function testMetadataWithoutDublinCoreElementsCanBeFilledIn(): void
    {
        $metadata = $this->load('<package xmlns="http://www.idpf.org/2007/opf" version="2.0"><metadata/><manifest/><spine/></package>');

        $this->assertSame('', $metadata->getTitle());
        $this->assertSame([], $metadata->getAuthors());

        $metadata->setTitle('Fresh');
        $metadata->setAuthors(['A. Writer']);
        $metadata->save();

        $reloaded = $this->reload();
        $this->assertSame('Fresh', $reloaded->getTitle());
        $this->assertSame(['A. Writer'], $reloaded->getAuthors());
    }

    public function testPackageWithoutMetadataElementThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing metadata element');

        $this->load('<package xmlns="http://www.idpf.org/2007/opf" version="3.0"><manifest/><spine/></package>');
    }

    public function testDublinCoreOutsideMetadataIsIgnored(): void
    {
        $metadata = $this->load(
            '<package xmlns="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/" version="3.0">'
            . '<metadata><dc:creator>Real Author</dc:creator></metadata>'
            . '<manifest/><spine/><guide><dc:creator>Not metadata</dc:creator></guide></package>'
        );

        $this->assertSame(['Real Author'], $metadata->getAuthors());
    }

    public function testValuesWithXmlSpecialCharactersRoundTrip(): void
    {
        $metadata = $this->load('<package xmlns="http://www.idpf.org/2007/opf" version="2.0"><metadata/><manifest/><spine/></package>');

        $metadata->setTitle('Pride & Prejudice <Annotated>');
        $metadata->setAuthors(['Tom & Jerry']);
        $metadata->save();

        $reloaded = $this->reload();
        $this->assertSame('Pride & Prejudice <Annotated>', $reloaded->getTitle());
        $this->assertSame(['Tom & Jerry'], $reloaded->getAuthors());
    }

    public function testSubjectsRoundTrip(): void
    {
        $metadata = $this->load(EpubBuilder::opf(metadata: '<dc:subject>Fiction</dc:subject><dc:subject>Adventure</dc:subject>'));
        $this->assertSame(['Fiction', 'Adventure'], $metadata->getSubjects());

        $metadata->setSubjects(['History', 'Maps', 'Travel']);
        $metadata->save();

        $this->assertSame(['History', 'Maps', 'Travel'], $this->reload()->getSubjects());
        $this->assertSame('History', $this->reload()->getSubject());
    }

    public function testEpubFileSavePersistsMetadataEdits(): void
    {
        $source = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid.epub';
        $epubFile = new EpubFile($source);
        $epubFile->load();
        $epubFile->getMetadata()->setTitle('Changed without Metadata::save()');
        $epubFile->save($this->epubPath);

        $reloaded = new EpubFile($this->epubPath);
        $reloaded->load();

        $this->assertSame('Changed without Metadata::save()', $reloaded->getMetadata()->getTitle());
    }

    private function epub2Opf(string $metadata): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="uid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">'
            . '<dc:identifier id="uid">urn:uuid:2</dc:identifier>' . $metadata
            . '</metadata><manifest/><spine/></package>';
    }

    private function load(string $opf): Metadata
    {
        file_put_contents($this->opfPath, $opf);

        return new Metadata((new XmlParser())->parse($this->opfPath), $this->opfPath);
    }

    private function reload(): Metadata
    {
        return new Metadata($this->reloadXml(), $this->opfPath);
    }

    private function reloadXml(): SimpleXMLElement
    {
        return (new XmlParser())->parse($this->opfPath);
    }

    /**
     * @return list<string>
     */
    private function values(SimpleXMLElement $xml, string $xpath): array
    {
        $xml->registerXPathNamespace('opf', self::OPF_NS);
        $xml->registerXPathNamespace('dc', self::DC_NS);

        return array_values(array_map(static fn (SimpleXMLElement $node): string => (string) $node, $xml->xpath($xpath) ?: []));
    }
}
