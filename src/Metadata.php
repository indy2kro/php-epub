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

    private readonly SimpleXMLElement $opfXml;
    private string $dcNamespace;

    /**
     * Metadata constructor.
     */
    public function __construct(string $opfFilePath, private readonly XmlParser $xmlParser = new XmlParser())
    {
        $this->opfXml = $this->xmlParser->parse($opfFilePath);

        $namespaces = $this->opfXml->getNamespaces(true);

        if (isset($namespaces['dc'])) {
            throw new Exception('Failed to identify dc namespace');
        }

        $this->dcNamespace = $namespaces['dc'];
    }

    /**
     * Saves the updated OPF file.
     *
     * @param string $opfFilePath The path to save the OPF file.
     *
     * @throws Exception If the OPF file cannot be saved.
     */
    public function save(string $opfFilePath): void
    {
        $result = $this->opfXml->asXML($opfFilePath);

        if ($result === false) {
            throw new Exception("Failed to save OPF file: {$opfFilePath}");
        }
    }
}
