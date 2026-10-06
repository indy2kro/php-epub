<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\XmlText;
use SimpleXMLElement;

class Metadata
{
    use Traits\InteractsWithTitle;
    use Traits\InteractsWithDescription;
    use Traits\InteractsWithDate;
    use Traits\InteractsWithAuthors;
    use Traits\InteractsWithContributors;
    use Traits\InteractsWithPublisher;
    use Traits\InteractsWithLanguage;
    use Traits\InteractsWithSubject;
    use Traits\InteractsWithIdentifier;

    public const string OPF_NAMESPACE = 'http://www.idpf.org/2007/opf';

    public const string DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    /**
     * Dublin Core elements the OPF specification requires with a non-empty value.
     */
    private const array REQUIRED_ELEMENTS = ['title', 'language', 'identifier'];

    private readonly SimpleXMLElement $metadataNode;

    private bool $modified = false;

    /**
     * Metadata constructor.
     *
     * @throws InvalidEpubException If the package has no metadata element.
     */
    public function __construct(private readonly SimpleXMLElement $opfXml, private string $opfFilePath)
    {
        $metadataNodes = $this->query($this->opfXml, '/opf:package/opf:metadata');

        if ($metadataNodes === []) {
            throw new InvalidEpubException('Missing metadata element in OPF file');
        }

        $this->metadataNode = $metadataNodes[0];
    }

    /**
     * Saves the updated OPF file.
     *
     * For EPUB 3 packages, the dcterms:modified date is updated when metadata was changed.
     *
     * @throws Exception If the OPF file cannot be saved.
     */
    public function save(): void
    {
        if ($this->modified && $this->isEpub3()) {
            $this->updateModifiedDate();
        }

        $result = $this->opfXml->asXML($this->opfFilePath);

        if ($result === false) {
            throw new Exception("Failed to save OPF file: {$this->opfFilePath}");
        }

        $this->modified = false;
    }

    /**
     * Whether metadata was changed since it was loaded or last saved.
     */
    public function isModified(): bool
    {
        return $this->modified;
    }

    /**
     * Records that the package changed outside the Dublin Core fields (e.g. manifest or spine),
     * so the next save() writes the OPF and refreshes the EPUB 3 modified date.
     */
    public function markModified(): void
    {
        $this->modified = true;
    }

    /**
     * The package version, e.g. "2.0" or "3.0".
     */
    public function getVersion(): string
    {
        return (string) $this->opfXml['version'];
    }

    /**
     * Gets an EPUB 2 style <meta name="…" content="…"/> value (e.g. "calibre:series", "cover").
     */
    public function getMeta(string $name): ?string
    {
        $meta = $this->namedMetas($name)[0] ?? null;

        return $meta instanceof SimpleXMLElement ? (string) $meta['content'] : null;
    }

    /**
     * Sets an EPUB 2 style <meta name="…" content="…"/> value; null removes it.
     * Duplicates with the same name are replaced by this single value.
     */
    public function setMeta(string $name, ?string $content): void
    {
        $this->setMetaValues($name, $content === null ? [] : [$content]);
    }

    /**
     * Gets every <meta name="…" content="…"/> value with this name, in document order.
     *
     * @return list<string>
     */
    public function getMetaValues(string $name): array
    {
        return array_map(static fn (SimpleXMLElement $meta): string => (string) $meta['content'], $this->namedMetas($name));
    }

    /**
     * Replaces every <meta name="…"> with this name by one element per value; [] removes them all.
     *
     * @param list<string> $values
     */
    public function setMetaValues(string $name, array $values): void
    {
        XmlText::assertValid($name, ...$values);
        $metas = $this->namedMetas($name);

        foreach ($values as $index => $value) {
            if (isset($metas[$index])) {
                $metas[$index]['content'] = $value;
            } else {
                $meta = $this->metadataNode->addChild('meta', null, self::OPF_NAMESPACE);
                $meta->addAttribute('name', $name);
                $meta->addAttribute('content', $value);
            }
        }

        foreach (array_slice($metas, count($values)) as $meta) {
            unset($meta[0]);
        }

        $this->modified = true;
    }

    /**
     * Gets an EPUB 3 <meta property="…">value</meta> that applies to the whole book
     * (refinements of other elements are ignored).
     */
    public function getProperty(string $property): ?string
    {
        $meta = $this->propertyMetas($property)[0] ?? null;

        return $meta instanceof SimpleXMLElement ? (string) $meta : null;
    }

    /**
     * Sets an EPUB 3 <meta property="…">value</meta> for the whole book; null removes it.
     * Duplicates with the same property are replaced by this single value.
     */
    public function setProperty(string $property, ?string $value): void
    {
        $this->setPropertyValues($property, $value === null ? [] : [$value]);
    }

    /**
     * Gets every book-level <meta property="…"> value with this property, in document order
     * (refinements of other elements are ignored).
     *
     * @return list<string>
     */
    public function getPropertyValues(string $property): array
    {
        return array_map(static fn (SimpleXMLElement $meta): string => (string) $meta, $this->propertyMetas($property));
    }

    /**
     * Replaces every book-level <meta property="…"> with this property by one element per
     * value; [] removes them all. Refinements of other elements are not touched.
     *
     * @param list<string> $values
     */
    public function setPropertyValues(string $property, array $values): void
    {
        XmlText::assertValid($property, ...$values);
        $metas = $this->propertyMetas($property);

        foreach ($values as $index => $value) {
            if (isset($metas[$index])) {
                $this->setText($metas[$index], $value);
            } else {
                $meta = $this->metadataNode->addChild('meta', htmlspecialchars($value, ENT_XML1), self::OPF_NAMESPACE);
                $meta->addAttribute('property', $property);
            }
        }

        foreach (array_slice($metas, count($values)) as $meta) {
            unset($meta[0]);
        }

        $this->modified = true;
    }

    public function getOpfFilePath(): string
    {
        return $this->opfFilePath;
    }

    /**
     * Returns the Dublin Core elements with the given name inside the metadata element.
     *
     * @return list<SimpleXMLElement>
     */
    protected function dcElements(string $name): array
    {
        return $this->query($this->metadataNode, './/dc:' . $name);
    }

    protected function getDcValue(string $name): string
    {
        $elements = $this->dcElements($name);

        return $elements === [] ? '' : (string) $elements[0];
    }

    /**
     * @return list<string>
     */
    protected function getDcValues(string $name): array
    {
        return array_map(static fn (SimpleXMLElement $element): string => (string) $element, $this->dcElements($name));
    }

    /**
     * Sets the first element with the given name, creating it when missing.
     */
    protected function setDcValue(string $name, string $value): void
    {
        $this->assertDcValues($name, [$value]);

        $elements = $this->dcElements($name);

        if ($elements === []) {
            $this->addDcElement($name, $value);
        } else {
            $this->setText($elements[0], $value);
        }

        $this->modified = true;
    }

    /**
     * Replaces all elements with the given name by $values.
     *
     * Existing elements are reused in order, so their id and attributes (e.g. an
     * EPUB 2 opf:role or an EPUB 3 role refinement) survive. When a value changes,
     * the attributes and refinement properties in $staleOnChange are dropped,
     * because they described the old value. Elements beyond $values are removed
     * together with every refinement that points at them.
     *
     * @param list<string> $values
     * @param list<SimpleXMLElement>|null $elements Elements to reuse, in order (defaults to all of them).
     * @param list<string> $staleOnChange Attribute / refinement property names, e.g. "file-as".
     */
    protected function setDcValues(string $name, array $values, ?array $elements = null, array $staleOnChange = []): void
    {
        // Check every value first, so one bad value leaves the package untouched.
        $this->assertDcValues($name, $values);

        $elements ??= $this->dcElements($name);

        foreach ($values as $index => $value) {
            if (! isset($elements[$index])) {
                $this->addDcElement($name, $value);
                continue;
            }

            if ((string) $elements[$index] !== $value) {
                $this->setText($elements[$index], $value);
                $this->removeProperties($elements[$index], $staleOnChange);
            }
        }

        foreach (array_slice($elements, count($values)) as $element) {
            $this->removeElement($element);
        }

        $this->modified = true;
    }

    /**
     * Returns the element referenced by package@unique-identifier, if any.
     */
    protected function uniqueIdentifierElement(): ?SimpleXMLElement
    {
        $uniqueId = (string) $this->opfXml['unique-identifier'];

        foreach ($this->dcElements('identifier') as $element) {
            if ($uniqueId !== '' && (string) $element['id'] === $uniqueId) {
                return $element;
            }
        }

        return null;
    }

    /**
     * The value of the EPUB 3 refinement (<meta refines="#id" property="…">) of an element, if any.
     */
    protected function refinementValue(SimpleXMLElement $element, string $property): ?string
    {
        foreach ($this->refinements($element) as $refinement) {
            if ((string) $refinement['property'] === $property) {
                return trim((string) $refinement);
            }
        }

        return null;
    }

    /**
     * @param list<string> $values
     *
     * @throws Exception If a value is not valid XML text, or empty for an element every package needs.
     */
    private function assertDcValues(string $name, array $values): void
    {
        XmlText::assertValid(...$values);

        if (in_array($name, self::REQUIRED_ELEMENTS, true)) {
            if ($values === []) {
                throw new Exception("dc:{$name} cannot be empty: every EPUB package needs one");
            }

            foreach ($values as $value) {
                if (trim($value) === '') {
                    throw new Exception("dc:{$name} cannot be empty: every EPUB package needs one");
                }
            }
        }
    }

    private function addDcElement(string $name, string $value): SimpleXMLElement
    {
        // addChild() does not escape "&", so pass the value pre-escaped.
        return $this->metadataNode->addChild('dc:' . $name, htmlspecialchars($value, ENT_XML1), self::DC_NAMESPACE);
    }

    /**
     * The role (MARC relator code) of a dc:creator / dc:contributor: the EPUB 2 opf:role
     * attribute or the EPUB 3 role refinement; null when there is none.
     */
    protected function personRole(SimpleXMLElement $element): ?string
    {
        return $this->personDetail($element, 'role');
    }

    protected function toContributor(SimpleXMLElement $element): Contributor
    {
        return new Contributor((string) $element, $this->personRole($element), $this->personDetail($element, 'file-as'));
    }

    /**
     * Adds a dc:creator or dc:contributor with an optional role and sort key.
     *
     * @throws Exception If a value is not valid XML text or the name is empty.
     */
    protected function addPerson(string $element, string $name, ?string $role, ?string $fileAs): void
    {
        XmlText::assertValid($name, $role ?? '', $fileAs ?? '');
        if (trim($name) === '') {
            throw new Exception("dc:{$element} cannot be empty");
        }

        $node = $this->addDcElement($element, $name);
        $details = array_filter(['role' => $role, 'file-as' => $fileAs], static fn (?string $value): bool => $value !== null && $value !== '');

        if ($details !== [] && $this->isEpub3()) {
            $id = $this->unusedId($element);
            $node->addAttribute('id', $id);

            foreach ($details as $property => $value) {
                $meta = $this->metadataNode->addChild('meta', htmlspecialchars($value, ENT_XML1), self::OPF_NAMESPACE);
                $meta->addAttribute('refines', '#' . $id);
                $meta->addAttribute('property', $property);
                if ($property === 'role') {
                    $meta->addAttribute('scheme', 'marc:relators');
                }
            }
        } else {
            foreach ($details as $property => $value) {
                $node->addAttribute('opf:' . $property, $value, self::OPF_NAMESPACE);
            }
        }

        $this->modified = true;
    }

    /**
     * An EPUB 2 opf:* attribute of a person, or else its EPUB 3 refinement; null when absent or empty.
     */
    private function personDetail(SimpleXMLElement $element, string $property): ?string
    {
        $attributes = $element->attributes(self::OPF_NAMESPACE);
        $value = $attributes !== null && isset($attributes[$property])
            ? trim((string) $attributes[$property])
            : $this->refinementValue($element, $property);

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * An id not used anywhere in the package, e.g. "creator-2".
     */
    private function unusedId(string $prefix): string
    {
        $used = array_map(static fn (SimpleXMLElement $attribute): string => (string) $attribute, $this->opfXml->xpath('//@id') ?: []);

        for ($suffix = 1; in_array("{$prefix}-{$suffix}", $used, true); $suffix++) {
        }

        return "{$prefix}-{$suffix}";
    }

    private function setText(SimpleXMLElement $element, string $value): void
    {
        // Assigning to [0] replaces the text content and escapes it.
        $element[0] = $value;
    }

    private function removeElement(SimpleXMLElement $element): void
    {
        foreach ($this->refinements($element) as $refinement) {
            unset($refinement[0]);
        }

        unset($element[0]);
    }

    /**
     * @param list<string> $properties
     */
    private function removeProperties(SimpleXMLElement $element, array $properties): void
    {
        foreach ($properties as $property) {
            $opfAttributes = $element->attributes(self::OPF_NAMESPACE);
            if ($opfAttributes !== null && isset($opfAttributes[$property])) {
                unset($opfAttributes[$property]);
            }

            foreach ($this->refinements($element) as $refinement) {
                if ((string) $refinement['property'] === $property) {
                    unset($refinement[0]);
                }
            }
        }
    }

    /**
     * EPUB 3 <meta refines="#id"> elements that describe the given element.
     *
     * @return list<SimpleXMLElement>
     */
    private function refinements(SimpleXMLElement $element): array
    {
        $id = (string) $element['id'];
        if ($id === '') {
            return [];
        }

        return array_values(array_filter(
            $this->query($this->metadataNode, './/opf:meta'),
            static fn (SimpleXMLElement $meta): bool => (string) $meta['refines'] === '#' . $id
        ));
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function namedMetas(string $name): array
    {
        return array_values(array_filter(
            $this->query($this->metadataNode, './/opf:meta'),
            static fn (SimpleXMLElement $meta): bool => (string) $meta['name'] === $name
        ));
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function propertyMetas(string $property): array
    {
        return array_values(array_filter(
            $this->query($this->metadataNode, './/opf:meta'),
            static fn (SimpleXMLElement $meta): bool => (string) $meta['property'] === $property && (string) $meta['refines'] === ''
        ));
    }

    private function isEpub3(): bool
    {
        return str_starts_with($this->getVersion(), '3');
    }

    private function updateModifiedDate(): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        foreach ($this->query($this->metadataNode, './/opf:meta') as $meta) {
            if ((string) $meta['property'] === 'dcterms:modified') {
                $this->setText($meta, $now);

                return;
            }
        }

        $meta = $this->metadataNode->addChild('meta', $now, self::OPF_NAMESPACE);
        $meta->addAttribute('property', 'dcterms:modified');
    }

    /**
     * Runs an XPath query with the opf and dc prefixes registered.
     *
     * @return list<SimpleXMLElement>
     */
    private function query(SimpleXMLElement $context, string $expression): array
    {
        $context->registerXPathNamespace('opf', self::OPF_NAMESPACE);
        $context->registerXPathNamespace('dc', self::DC_NAMESPACE);

        $result = $context->xpath($expression);

        return $result === false || $result === null ? [] : array_values($result);
    }
}
