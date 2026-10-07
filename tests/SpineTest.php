<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PhpEpub\Manifest;
use PhpEpub\Spine;
use PhpEpub\Test\Support\EpubBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

final class SpineTest extends TestCase
{
    private SimpleXMLElement $opfXml;

    protected function setUp(): void
    {
        $opf = str_replace(
            '<itemref idref="chapter"/>',
            '<itemref idref="chapter" id="ref-chapter" properties="page-spread-left"/><itemref idref="notes" linear="no"/>',
            EpubBuilder::opf(
                '<item id="notes" href="text/notes.xhtml" media-type="application/xhtml+xml"/>'
                . '<item id="extra" href="text/extra.xhtml" media-type="application/xhtml+xml"/>'
            )
        );
        $this->opfXml = new SimpleXMLElement($opf);
    }

    public function testGetKeepsReturningIdrefs(): void
    {
        $this->assertSame(['chapter', 'notes'], (new Spine($this->opfXml))->get());
    }

    public function testGetItemsResolvesManifestEntriesAndLinearFlag(): void
    {
        $items = $this->spine()->getItems();

        $this->assertCount(2, $items);
        $this->assertSame('chapter', $items[0]->idref);
        $this->assertTrue($items[0]->linear);
        $this->assertSame('EPUB/chapter.xhtml', $items[0]->item?->path);
        $this->assertSame('notes', $items[1]->idref);
        $this->assertFalse($items[1]->linear);
        $this->assertSame('EPUB/text/notes.xhtml', $items[1]->item?->path);
    }

    public function testGetItemsWithoutManifestLeavesItemEmpty(): void
    {
        $items = (new Spine($this->opfXml))->getItems();

        $this->assertNull($items[0]->item);
    }

    public function testAddAppendsOrInsertsAndWritesTheOpf(): void
    {
        $spine = $this->spine();

        $spine->add('extra', 1, false);

        $this->assertSame(['chapter', 'extra', 'notes'], $spine->get());
        $this->assertTrue($spine->isModified());
        $this->assertSame(['chapter', 'extra', 'notes'], (new Spine($this->opfXml))->get());
        $this->assertFalse((new Spine($this->opfXml))->getItems()[1]->linear);
        $this->assertFalse((new Spine($this->opfXml))->getItems()[2]->linear);

        // Rewriting the spine keeps the other itemref attributes.
        $this->opfXml->registerXPathNamespace('opf', 'http://www.idpf.org/2007/opf');
        $chapter = ($this->opfXml->xpath("//opf:itemref[@idref='chapter']") ?: [])[0] ?? null;
        $this->assertInstanceOf(SimpleXMLElement::class, $chapter);
        $this->assertSame('ref-chapter', (string) $chapter['id']);
        $this->assertSame('page-spread-left', (string) $chapter['properties']);
    }

    public function testAddAppendsByDefault(): void
    {
        $spine = $this->spine();
        $spine->remove('notes');

        $spine->add('notes');

        $this->assertSame(['chapter', 'notes'], (new Spine($this->opfXml))->get());
        $this->assertTrue((new Spine($this->opfXml))->getItems()[1]->linear);
    }

    public function testAddRejectsUnknownAndDuplicateIdrefs(): void
    {
        $spine = $this->spine();

        try {
            $spine->add('missing');
            $this->fail('Expected an exception for an idref that is not in the manifest.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('not in the manifest', $exception->getMessage());
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('already in the spine');

        $spine->add('chapter');
    }

    public function testRemoveAndMove(): void
    {
        $spine = $this->spine();
        $spine->add('extra');

        $spine->move('extra', 0);
        $this->assertSame(['extra', 'chapter', 'notes'], (new Spine($this->opfXml))->get());

        $spine->remove('chapter');
        $this->assertSame(['extra', 'notes'], (new Spine($this->opfXml))->get());
        $this->assertSame(['extra', 'notes'], $spine->get());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not in the spine');

        $spine->remove('chapter');
    }

    public function testContainsAndMarkSaved(): void
    {
        $spine = $this->spine();
        $this->assertTrue($spine->contains('notes'));
        $this->assertFalse($spine->contains('extra'));
        $this->assertFalse($spine->isModified());

        $spine->remove('notes');
        $spine->markSaved();

        $this->assertFalse($spine->isModified());
    }

    public function testSetLinearChangesAnExistingEntryAndKeepsItsAttributes(): void
    {
        $spine = $this->spine();

        $spine->setLinear('chapter', false);
        $spine->setLinear('notes', true);

        $items = (new Spine($this->opfXml))->getItems();
        $this->assertFalse($items[0]->linear);
        $this->assertTrue($items[1]->linear);
        $this->assertStringContainsString('<itemref idref="chapter" id="ref-chapter" properties="page-spread-left" linear="no"/>', (string) $this->opfXml->asXML());
        $this->assertStringContainsString('<itemref idref="notes"/>', (string) $this->opfXml->asXML());
        $this->assertTrue($spine->isModified());
    }

    public function testSetLinearRejectsAnItemNotInTheSpine(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Item "extra" is not in the spine');

        $this->spine()->setLinear('extra', false);
    }

    public function testPageProgressionDirection(): void
    {
        $spine = $this->spine();
        $this->assertNull($spine->getPageProgressionDirection());

        $spine->setPageProgressionDirection('rtl');
        $this->assertSame('rtl', (new Spine($this->opfXml))->getPageProgressionDirection());

        $spine->setPageProgressionDirection(null);
        $this->assertNull((new Spine($this->opfXml))->getPageProgressionDirection());
        $this->assertTrue($spine->isModified());
    }

    public function testPageProgressionDirectionRejectsOtherValues(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('page-progression-direction must be "ltr", "rtl" or "default", got: up');

        $this->spine()->setPageProgressionDirection('up');
    }

    public function testPageProgressionDirectionIsEpub3Only(): void
    {
        $opfXml = new SimpleXMLElement(str_replace('version="3.0"', 'version="2.0"', EpubBuilder::opf()));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('EPUB 3');

        (new Spine($opfXml))->setPageProgressionDirection('rtl');
    }

    #[DataProvider('positionsOutOfRange')]
    public function testPositionsOutOfRangeAreRejected(string $method, int $position): void
    {
        $spine = $this->spine();

        try {
            $method === 'add' ? $spine->add('extra', $position) : $spine->move('notes', $position);
            $this->fail("Expected an exception for position {$position}.");
        } catch (Exception $exception) {
            $this->assertStringContainsString("Position {$position} is outside the spine", $exception->getMessage());
        }

        $this->assertSame(['chapter', 'notes'], $spine->get());
        $this->assertFalse($spine->isModified());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function positionsOutOfRange(): iterable
    {
        yield 'add before the start' => ['add', -1];
        yield 'add past the end' => ['add', 3];
        yield 'move before the start' => ['move', -1];
        yield 'move past the end' => ['move', 2];
    }

    public function testTheLastPositionsAreAllowed(): void
    {
        $spine = $this->spine();

        $spine->add('extra', 2);
        $spine->move('chapter', 2);

        $this->assertSame(['notes', 'extra', 'chapter'], $spine->get());
    }

    private function spine(): Spine
    {
        return new Spine($this->opfXml, new Manifest($this->opfXml, 'EPUB/package.opf'));
    }
}
