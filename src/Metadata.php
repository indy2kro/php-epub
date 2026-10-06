<?php

declare(strict_types=1);

namespace PhpEpub;

use SimpleXMLElement;

class Metadata
{
    use Traits\InteractsWithTitle;
    use Traits\InteractsWithDescription;
    use Traits\InteractsWithDate;
    use Traits\InteractsWithAuthors;
    use Traits\InteractsWithPublisher;
    use Traits\InteractsWithLanguage;
    use Traits\InteractsWithSubject;
    use Traits\InteractsWithIdentifier;

    public const string OPF_NAMESPACE = 'http://www.idpf.org/2007/opf';

    public const string DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

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

    private function addDcElement(string $name, string $value): void
    {
        // addChild() does not escape "&", so pass the value pre-escaped.
        $this->metadataNode->addChild('dc:' . $name, htmlspecialchars($value, ENT_XML1), self::DC_NAMESPACE);
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

    private function isEpub3(): bool
    {
        return str_starts_with((string) $this->opfXml['version'], '3');
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
