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

    private readonly string $dcNamespace;

    /**
     * Metadata constructor.
     */
    public function __construct(private readonly SimpleXMLElement $opfXml, private string $opfFilePath)
    {
        $namespaces = $this->opfXml->getNamespaces(true);

        if (! isset($namespaces['dc'])) {
            throw new Exception('Failed to identify dc namespace');
        }

        $this->dcNamespace = $namespaces['dc'];
    }

    /**
     * Saves the updated OPF file.
     *
     * @throws Exception If the OPF file cannot be saved.
     */
    public function save(): void
    {
        $result = $this->opfXml->asXML($this->opfFilePath);

        if ($result === false) {
            throw new Exception("Failed to save OPF file: {$this->opfFilePath}");
        }
    }

    public function getOpfFilePath(): string
    {
        return $this->opfFilePath;
    }
}
