<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use PhpEpub\Metadata;
use SimpleXMLElement;

trait InteractsWithDate
{
    /**
     * Gets the publication date of the EPUB: the dc:date with the EPUB 2 opf:event
     * "publication", or else the first dc:date without an event, or else the first dc:date.
     */
    public function getDate(): string
    {
        return $this->getDcValues('date')[$this->publicationDateIndex()] ?? '';
    }

    /**
     * Sets the publication date of the EPUB (see getDate()), creating dc:date when missing.
     * Dates of other events (creation, modification) are kept.
     */
    public function setDate(string $date): void
    {
        $dates = $this->getDcValues('date');
        $dates[$this->publicationDateIndex()] = $date;

        $this->setDcValues('date', array_values($dates));
    }

    /**
     * Gets the last modification date: the EPUB 3 dcterms:modified property, or else the
     * dc:date with the EPUB 2 opf:event "modification"; null when the book has neither.
     */
    public function getModifiedDate(): ?string
    {
        return $this->getProperty('dcterms:modified') ?? ($this->getDateEvents()['modification'] ?? null);
    }

    /**
     * Gets every dc:date keyed by its EPUB 2 opf:event (e.g. "publication", "creation",
     * "modification"); a date without an event has the key "". The first date of each event wins.
     *
     * @return array<string, string>
     */
    public function getDateEvents(): array
    {
        $events = [];
        foreach ($this->dcElements('date') as $element) {
            $events[$this->dateEvent($element)] ??= (string) $element;
        }

        return $events;
    }

    private function publicationDateIndex(): int
    {
        $elements = $this->dcElements('date');

        foreach (['publication', ''] as $event) {
            foreach ($elements as $index => $element) {
                if ($this->dateEvent($element) === $event) {
                    return $index;
                }
            }
        }

        return 0;
    }

    private function dateEvent(SimpleXMLElement $element): string
    {
        // Usually opf:event, but some books write a plain "event" attribute.
        $event = (string) ($element->attributes(Metadata::OPF_NAMESPACE)['event'] ?? $element['event'] ?? '');

        return strtolower(trim($event));
    }
}
