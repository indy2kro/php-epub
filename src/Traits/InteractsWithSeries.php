<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use PhpEpub\Exception;
use PhpEpub\Metadata;
use PhpEpub\Util\XmlText;
use SimpleXMLElement;

/**
 * Series metadata, which books record in two ways: the EPUB 3 belongs-to-collection meta refined
 * with collection-type "series" (and group-position), and Calibre's calibre:series and
 * calibre:series_index metas, which EPUB 2 books and many reading systems use.
 */
trait InteractsWithSeries
{
    /**
     * The series the book belongs to: the EPUB 3 series collection, or else calibre:series;
     * null when the book has neither.
     */
    public function getSeries(): ?string
    {
        $collection = $this->seriesCollection();

        return $this->nonEmpty($collection instanceof SimpleXMLElement ? (string) $collection : $this->getMeta('calibre:series'));
    }

    /**
     * The book's position in its series as written (e.g. "2" or "2.5"): the group-position of
     * the EPUB 3 series collection, or else calibre:series_index; null when there is none.
     */
    public function getSeriesIndex(): ?string
    {
        $collection = $this->seriesCollection();

        return $this->nonEmpty($collection instanceof SimpleXMLElement
            ? $this->refinementValue($collection, 'group-position')
            : $this->getMeta('calibre:series_index'));
    }

    /**
     * Sets the series in both conventions: calibre:series (and calibre:series_index), and in EPUB 3
     * packages a belongs-to-collection meta with collection-type "series" (and group-position),
     * which replaces the previous series collection. Other collections are kept. null removes the series.
     *
     * @param int|float|string|null $index The position in the series, a number such as 2 or "2.5".
     *
     * @throws Exception If the name is empty or not valid XML text, or the index is not a number.
     */
    public function setSeries(?string $name, int|float|string|null $index = null): void
    {
        $index = $index === null ? null : trim((string) $index);
        if ($name !== null) {
            XmlText::assertValid($name);
            $name = trim($name);
        }

        if ($name === '') {
            throw new Exception('The series name cannot be empty');
        }

        if ($index !== null && ! is_numeric($index)) {
            throw new Exception("The series index must be a number, got: {$index}");
        }

        $collection = $this->seriesCollection();
        if ($collection instanceof SimpleXMLElement) {
            $this->removeElement($collection);
        }

        $this->setMeta('calibre:series', $name);
        $this->setMeta('calibre:series_index', $name === null ? null : $index);

        if ($name !== null && $this->isEpub3()) {
            $id = $this->unusedId('series');
            $meta = $this->metadataNode->addChild('meta', htmlspecialchars($name, ENT_XML1), Metadata::OPF_NAMESPACE);
            $meta->addAttribute('property', 'belongs-to-collection');
            $meta->addAttribute('id', $id);

            foreach (['collection-type' => 'series', 'group-position' => $index] as $property => $value) {
                if ($value !== null) {
                    $refinement = $this->metadataNode->addChild('meta', $value, Metadata::OPF_NAMESPACE);
                    $refinement->addAttribute('refines', '#' . $id);
                    $refinement->addAttribute('property', $property);
                }
            }
        }

        $this->modified = true;
    }

    /**
     * The first book-level belongs-to-collection meta refined with collection-type "series".
     */
    private function seriesCollection(): ?SimpleXMLElement
    {
        foreach ($this->propertyMetas('belongs-to-collection') as $collection) {
            if ($this->refinementValue($collection, 'collection-type') === 'series') {
                return $collection;
            }
        }

        return null;
    }

    private function nonEmpty(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
