<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use DateTimeImmutable;
use PhpEpub\Contributor;
use PhpEpub\Exception;
use PhpEpub\Metadata;
use PhpEpub\XmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Language and date syntax, creators and contributors as objects, accessibility metadata and the
 * modification date; every test saves the package and reads it back.
 */
final class MetadataFieldsTest extends TestCase
{
    private const string OPF_NS = 'http://www.idpf.org/2007/opf';
    private const string DC_NS = 'http://purl.org/dc/elements/1.1/';

    private string $opfPath;

    protected function setUp(): void
    {
        $tmp = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp';
        if (! is_dir($tmp)) {
            mkdir($tmp, 0777, true);
        }

        $this->opfPath = $tmp . DIRECTORY_SEPARATOR . 'fields.opf';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->opfPath)) {
            unlink($this->opfPath);
        }
    }

    #[DataProvider('validLanguages')]
    public function testSetLanguageAcceptsWellFormedTags(string $tag): void
    {
        $metadata = $this->load($this->epub3(''));

        $metadata->setLanguage($tag);

        $this->assertSame($tag, $metadata->getLanguage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validLanguages(): iterable
    {
        foreach (['en', 'fr', 'eng', 'en-US', 'zh-Hant-TW', 'sr-Latn-RS', 'es-419', 'de-CH-1996', 'en-u-ca-gregory', 'x-private', 'en-x-twain', 'EN-us'] as $tag) {
            yield $tag => [$tag];
        }
    }

    #[DataProvider('invalidLanguages')]
    public function testSetLanguageRejectsMalformedTags(string $tag): void
    {
        $metadata = $this->load($this->epub3(''));

        try {
            $metadata->setLanguage($tag);
            $this->fail('A malformed language tag was accepted.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('BCP 47', $exception->getMessage());
        }

        $this->assertSame('en', $metadata->getLanguage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLanguages(): iterable
    {
        foreach (['English', 'e', 'en_US', 'en-', '-en', 'en US', 'en--US', 'en-US-', '123', 'en-toolongsubtag', 'x-', 'en-a'] as $tag) {
            yield $tag => [$tag];
        }
    }

    public function testSetLanguagesChecksEveryTagBeforeChangingAnything(): void
    {
        $metadata = $this->load($this->epub3('<dc:language>fr</dc:language>'));

        try {
            $metadata->setLanguages(['de', 'Klingon']);
            $this->fail('A malformed language tag was accepted.');
        } catch (Exception) {
            $this->assertSame(['en', 'fr'], $metadata->getLanguages());
        }

        $metadata->setLanguages(['de', 'pt-BR']);
        $this->assertSame(['de', 'pt-BR'], $metadata->getLanguages());
    }

    #[DataProvider('validDates')]
    public function testSetDateAcceptsW3cdtf(string $date): void
    {
        $metadata = $this->load($this->epub3(''));

        $metadata->setDate($date);
        $metadata->save();

        $this->assertSame($date, $this->reload()->getDate());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validDates(): iterable
    {
        foreach (['2020', '2020-07', '2020-07-31', '2020-02-29', '2020-07-31T10:20Z', '2020-07-31T10:20:30Z', '2020-07-31T10:20:30.45+02:00', '2020-07-31T23:59:59-05:30'] as $date) {
            yield $date => [$date];
        }
    }

    #[DataProvider('invalidDates')]
    public function testSetDateRejectsOtherFormats(string $date): void
    {
        $metadata = $this->load($this->epub3('<dc:date>2001-01-01</dc:date>'));

        try {
            $metadata->setDate($date);
            $this->fail('A malformed date was accepted.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('W3CDTF', $exception->getMessage());
        }

        $this->assertSame('2001-01-01', $metadata->getDate());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        foreach (['', 'July 2020', '2020-7-1', '2020-13', '2020-02-30', '2021-02-29', '20200731', '2020-07-31T10:20', '2020-07-31 10:20:30Z', '2020-07-31T24:00Z', '2020-07-31T10:20:30+0200', '99'] as $date) {
            yield $date => [$date];
        }
    }

    public function testSetDateAcceptsADateTime(): void
    {
        $metadata = $this->load($this->epub3(''));

        $metadata->setDate(new DateTimeImmutable('2020-07-31 10:20:30', new \DateTimeZone('Europe/Paris')));

        $this->assertSame('2020-07-31T10:20:30+02:00', $metadata->getDate());
    }

    public function testSetDateRejectsADateTimeW3cdtfCannotWrite(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('W3CDTF');

        $this->load($this->epub3(''))->setDate((new DateTimeImmutable())->setDate(-44, 3, 15));
    }

    public function testSaveUpdatesTheBookLevelModifiedDateNotARefinement(): void
    {
        $metadata = $this->load($this->epub3(
            '<dc:creator id="c1">Ann</dc:creator>'
            . '<meta refines="#c1" property="dcterms:modified">1999-01-01T00:00:00Z</meta>'
            . '<meta property="dcterms:modified">2000-01-01T00:00:00Z</meta>'
        ));

        $metadata->setTitle('Edited');
        $metadata->save();

        $xml = $this->reloadXml();
        $this->assertSame(['1999-01-01T00:00:00Z'], $this->values($xml, "//opf:meta[@refines='#c1'][@property='dcterms:modified']"));
        $bookLevel = $this->values($xml, "//opf:meta[not(@refines)][@property='dcterms:modified']");
        $this->assertCount(1, $bookLevel);
        $this->assertNotSame('2000-01-01T00:00:00Z', $bookLevel[0]);
    }

    public function testSaveAddsABookLevelModifiedDateWhenOnlyARefinementExists(): void
    {
        $metadata = $this->load($this->epub3(
            '<dc:creator id="c1">Ann</dc:creator><meta refines="#c1" property="dcterms:modified">1999-01-01T00:00:00Z</meta>'
        ));

        $metadata->setTitle('Edited');
        $metadata->save();

        $xml = $this->reloadXml();
        $this->assertSame(['1999-01-01T00:00:00Z'], $this->values($xml, "//opf:meta[@refines][@property='dcterms:modified']"));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $this->values($xml, "//opf:meta[not(@refines)][@property='dcterms:modified']")[0] ?? ''
        );
    }

    public function testSetCreatorsWritesRolesAndSortKeysInEpub3(): void
    {
        $metadata = $this->load($this->epub3(
            '<dc:creator id="a1">Ann Author</dc:creator>'
            . '<meta refines="#a1" property="role" scheme="marc:relators">aut</meta>'
            . '<meta refines="#a1" property="file-as">Author, Ann</meta>'
            . '<dc:creator>Ivan Illustrator</dc:creator>'
        ));

        $metadata->setCreators([
            new Contributor('Ann Author', 'aut', 'Writer, Ann'),
            new Contributor('Ivan Illustrator', 'ill', null),
            new Contributor('Cy Cartographer', null, 'Cartographer, Cy'),
        ]);
        $metadata->save();

        $reloaded = $this->reload();
        $this->assertEquals([
            new Contributor('Ann Author', 'aut', 'Writer, Ann'),
            new Contributor('Ivan Illustrator', 'ill', null),
            new Contributor('Cy Cartographer', null, 'Cartographer, Cy'),
        ], $reloaded->getCreators());
        $xml = $this->reloadXml();
        // The first creator keeps its id and its role refinement (with the scheme).
        $this->assertSame(['a1'], $this->values($xml, '//dc:creator[1]/@id'));
        $this->assertSame(['marc:relators', 'marc:relators'], $this->values($xml, "//opf:meta[@property='role']/@scheme"));
    }

    public function testSetCreatorsRemovesRolesSortKeysAndSurplusCreators(): void
    {
        $metadata = $this->load($this->epub3(
            '<dc:creator id="a1">Ann Author</dc:creator>'
            . '<meta refines="#a1" property="role">aut</meta>'
            . '<meta refines="#a1" property="file-as">Author, Ann</meta>'
            . '<dc:creator id="a2">Bob</dc:creator><meta refines="#a2" property="role">ill</meta>'
        ));

        $metadata->setCreators([new Contributor('Ann Author', null, null)]);
        $metadata->save();

        $this->assertEquals([new Contributor('Ann Author', null, null)], $this->reload()->getCreators());
        $this->assertSame([], $this->values($this->reloadXml(), '//opf:meta[@refines]'));
    }

    public function testSetCreatorsInEpub2UsesOpfAttributes(): void
    {
        $metadata = $this->load($this->epub2(
            '<dc:creator opf:role="aut" opf:file-as="Author, Ann">Ann Author</dc:creator>'
        ));

        $metadata->setCreators([
            new Contributor('Ann Author', 'edt', null),
            new Contributor('Bob', 'ill', 'Bob, B'),
        ]);
        $metadata->save();

        $xml = $this->reloadXml();
        $this->assertSame(['edt', 'ill'], $this->values($xml, '//dc:creator/@opf:role'));
        $this->assertSame(['Bob, B'], $this->values($xml, '//dc:creator/@opf:file-as'));
        $this->assertEquals([new Contributor('Ann Author', 'edt', null), new Contributor('Bob', 'ill', 'Bob, B')], $this->reload()->getCreators());
    }

    public function testSetCreatorsAssignsAnIdWhenAnEpub3CreatorNeedsARefinement(): void
    {
        $metadata = $this->load($this->epub3('<dc:creator>Ann</dc:creator>'));

        $metadata->setCreators([new Contributor('Ann', 'aut', null)]);

        $this->assertEquals([new Contributor('Ann', 'aut', null)], $metadata->getCreators());
        $this->assertMatchesRegularExpression('/id="creator-1"/', $this->saved($metadata));
    }

    public function testSetCreatorsKeepsCreatorsUntouchedWhenOneIsInvalid(): void
    {
        $metadata = $this->load($this->epub3('<dc:creator>Ann</dc:creator>'));

        foreach ([new Contributor(' ', null, null), new Contributor('Bob', "r\x01", null)] as $bad) {
            try {
                $metadata->setCreators([new Contributor('Cy', null, null), $bad]);
                $this->fail('An invalid creator was accepted.');
            } catch (Exception) {
                $this->assertEquals([new Contributor('Ann', null, null)], $metadata->getCreators());
            }
        }
    }

    public function testSetContributorsAcceptsNamesAndContributorObjects(): void
    {
        $metadata = $this->load($this->epub3(
            '<dc:contributor id="e1">Old Editor</dc:contributor>'
            . '<meta refines="#e1" property="role">edt</meta>'
            . '<meta refines="#e1" property="file-as">Editor, Old</meta>'
        ));

        // A plain name keeps the role of the element it reuses and drops its stale sort key.
        $metadata->setContributors(['New Editor', new Contributor('Tess Translator', 'trl', 'Translator, Tess')]);

        $this->assertEquals([
            new Contributor('New Editor', 'edt', null),
            new Contributor('Tess Translator', 'trl', 'Translator, Tess'),
        ], $metadata->getContributors());

        $metadata->setContributors([new Contributor('Only', null, null)]);

        $this->assertEquals([new Contributor('Only', null, null)], $metadata->getContributors());
    }

    public function testAccessibilityMetadataInEpub3(): void
    {
        $metadata = $this->load($this->epub3(''));

        $this->assertSame([], $metadata->getAccessModes());
        $this->assertSame([], $metadata->getAccessModesSufficient());
        $this->assertSame([], $metadata->getAccessibilityFeatures());
        $this->assertSame([], $metadata->getAccessibilityHazards());
        $this->assertNull($metadata->getAccessibilitySummary());
        $this->assertNull($metadata->getConformsTo());
        $this->assertNull($metadata->getCertifiedBy());

        $metadata->setAccessModes(['textual', 'visual']);
        $metadata->setAccessModesSufficient(['textual,visual', 'textual']);
        $metadata->setAccessibilityFeatures(['alternativeText', 'tableOfContents']);
        $metadata->setAccessibilityHazards(['none']);
        $metadata->setAccessibilitySummary('Images have text alternatives & the book is navigable.');
        $metadata->setConformsTo('EPUB Accessibility 1.1 - WCAG 2.1 Level AA');
        $metadata->setCertifiedBy('Example Certifier');
        $metadata->save();

        $reloaded = $this->reload();
        $this->assertSame(['textual', 'visual'], $reloaded->getAccessModes());
        $this->assertSame(['textual,visual', 'textual'], $reloaded->getAccessModesSufficient());
        $this->assertSame(['alternativeText', 'tableOfContents'], $reloaded->getAccessibilityFeatures());
        $this->assertSame(['none'], $reloaded->getAccessibilityHazards());
        $this->assertSame('Images have text alternatives & the book is navigable.', $reloaded->getAccessibilitySummary());
        $this->assertSame('EPUB Accessibility 1.1 - WCAG 2.1 Level AA', $reloaded->getConformsTo());
        $this->assertSame('Example Certifier', $reloaded->getCertifiedBy());

        $xml = $this->reloadXml();
        $this->assertSame(['textual', 'visual'], $this->values($xml, "//opf:meta[@property='schema:accessMode']"));
        $this->assertSame(['textual,visual', 'textual'], $this->values($xml, "//opf:meta[@property='schema:accessModeSufficient']"));
        $this->assertSame(['none'], $this->values($xml, "//opf:meta[@property='schema:accessibilityHazard']"));
        $this->assertSame(['Example Certifier'], $this->values($xml, "//opf:meta[@property='a11y:certifiedBy']"));
        $this->assertSame([], $this->values($xml, '//opf:meta[@name]'));

        $reloaded->setAccessModes([]);
        $reloaded->setAccessibilitySummary(null);
        $reloaded->setConformsTo(null);
        $reloaded->setCertifiedBy('');

        $this->assertSame([], $reloaded->getAccessModes());
        $this->assertNull($reloaded->getAccessibilitySummary());
        $this->assertNull($reloaded->getConformsTo());
        $this->assertNull($reloaded->getCertifiedBy());
    }

    public function testAccessibilityMetadataInEpub2UsesNamedMetas(): void
    {
        $metadata = $this->load($this->epub2(''));

        $metadata->setAccessModes(['textual']);
        $metadata->setAccessModesSufficient(['textual']);
        $metadata->setAccessibilityFeatures(['tableOfContents', 'structuralNavigation']);
        $metadata->setAccessibilityHazards(['noFlashingHazard']);
        $metadata->setAccessibilitySummary('Summary');
        $metadata->setConformsTo('EPUB Accessibility 1.1 - WCAG 2.1 Level A');
        $metadata->setCertifiedBy('Certifier');
        $metadata->save();

        $reloaded = $this->reload();
        $this->assertSame(['textual'], $reloaded->getAccessModes());
        $this->assertSame(['tableOfContents', 'structuralNavigation'], $reloaded->getAccessibilityFeatures());
        $this->assertSame('Summary', $reloaded->getAccessibilitySummary());
        $this->assertSame('EPUB Accessibility 1.1 - WCAG 2.1 Level A', $reloaded->getConformsTo());
        $this->assertSame('Certifier', $reloaded->getCertifiedBy());
        $xml = $this->reloadXml();
        $this->assertSame(['tableOfContents', 'structuralNavigation'], $this->values($xml, "//opf:meta[@name='schema:accessibilityFeature']/@content"));
        $this->assertSame(['noFlashingHazard'], $this->values($xml, "//opf:meta[@name='schema:accessibilityHazard']/@content"));
        $this->assertSame([], $this->values($xml, '//opf:meta[@property]'));
    }

    public function testAccessibilityValuesMustBeValidXmlText(): void
    {
        $metadata = $this->load($this->epub3(''));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not valid XML text');

        $metadata->setAccessibilitySummary("Bad\x01");
    }

    private function epub3(string $metadata): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:identifier id="uid">urn:uuid:3</dc:identifier><dc:title>T</dc:title><dc:language>en</dc:language>' . $metadata
            . '</metadata><manifest/><spine/></package>';
    }

    private function epub2(string $metadata): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="uid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">'
            . '<dc:identifier id="uid">urn:uuid:2</dc:identifier><dc:title>T</dc:title><dc:language>en</dc:language>' . $metadata
            . '</metadata><manifest/><spine/></package>';
    }

    private function load(string $opf): Metadata
    {
        file_put_contents($this->opfPath, $opf);

        return new Metadata((new XmlParser())->parse($this->opfPath), $this->opfPath);
    }

    private function saved(Metadata $metadata): string
    {
        $metadata->save();

        return (string) file_get_contents($this->opfPath);
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
