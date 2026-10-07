<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use SimpleXMLElement;

/**
 * The metadata part of EpubFile::upgradeToEpub3(): EPUB 3 does not allow the EPUB 2 opf:* attributes
 * on Dublin Core elements, so they become refinements or are dropped.
 */
trait UpgradesToEpub3
{
    /**
     * dc:date events that EPUB 3 keeps as dcterms properties, because a package has one dc:date only.
     */
    private const array DATE_EVENT_PROPERTIES = [
        'creation' => 'dcterms:created',
        'issued' => 'dcterms:issued',
        'copyright' => 'dcterms:dateCopyrighted',
    ];

    /**
     * Turns the package metadata into EPUB 3 metadata, and the package version into "3.0":
     *
     * - opf:role and opf:file-as of creators and contributors (opf:file-as of other elements) become
     *   role / file-as refinements, and the opf:scheme of an ISBN or DOI identifier an identifier-type refinement;
     * - the one dc:date that stays is the publication date (the one without an event, or with the "publication"
     *   event); dates of the creation, issued and copyright events become dcterms:created, dcterms:issued
     *   and dcterms:dateCopyrighted properties, and any other date is dropped;
     * - every other opf:* attribute of a Dublin Core element is removed;
     * - dcterms:modified is set to now.
     *
     * @internal Called by EpubFile::upgradeToEpub3(), which also converts the rest of the book.
     */
    public function upgradeToEpub3(): void
    {
        $this->opfXml['version'] = '3.0';

        $this->upgradeDates();

        foreach ($this->query($this->metadataNode, './dc:*') as $element) {
            $details = [];
            foreach ($element->attributes(self::OPF_NAMESPACE) ?? [] as $name => $value) {
                $details[(string) $name] = trim((string) $value);
            }

            // Only people have a role.
            $isPerson = in_array($element->getName(), ['creator', 'contributor'], true);
            if ($isPerson && ($details['role'] ?? '') !== '') {
                $this->writePersonDetail($element, 'role', $details['role']);
            }

            if (($details['file-as'] ?? '') !== '') {
                $this->writePersonDetail($element, 'file-as', $details['file-as']);
            }

            $identifierType = $element->getName() === 'identifier' ? $this->identifierType($details['scheme'] ?? '', (string) $element) : null;
            if ($identifierType !== null) {
                $this->addRefinement($element, 'identifier-type', $identifierType, 'onix:codelist5');
            }

            $attributes = $element->attributes(self::OPF_NAMESPACE);
            foreach ($attributes instanceof SimpleXMLElement ? array_keys($details) : [] as $name) {
                unset($attributes[$name]);
            }
        }

        $this->setProperty('dcterms:modified', gmdate('Y-m-d\TH:i:s\Z'));
    }

    private function upgradeDates(): void
    {
        $dates = $this->dcElements('date');
        $keepIndex = $this->publicationDateIndex();

        foreach ($dates as $index => $date) {
            $event = $this->dateEvent($date);
            $property = self::DATE_EVENT_PROPERTIES[$event] ?? null;
            if ($property !== null && $this->getProperty($property) === null && trim((string) $date) !== '') {
                $this->setProperty($property, trim((string) $date));
            }

            if ($index === $keepIndex && in_array($event, ['', 'publication'], true)) {
                unset($date['event']);

                continue;
            }

            unset($date[0]);
        }
    }

    /**
     * The ONIX code list 5 code of an identifier scheme: "15" for an ISBN-13, "02" for an ISBN-10,
     * "06" for a DOI; null for any other scheme.
     */
    private function identifierType(string $scheme, string $value): ?string
    {
        $scheme = strtolower($scheme);
        $length = strlen((string) preg_replace('/[^0-9Xx]/', '', $value));

        return match (true) {
            $scheme === 'doi' => '06',
            $scheme === 'isbn' && $length === 13 => '15',
            $scheme === 'isbn' && $length === 10 => '02',
            default => null,
        };
    }
}
