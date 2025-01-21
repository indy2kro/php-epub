<?php

declare(strict_types=1);

namespace PhpEpub;

use SimpleXMLElement;

class XmlParser
{
    /**
     * Loads an XML file and returns a SimpleXMLElement.
     *
     * @param string $filePath The path to the XML file.
     *
     * @throws Exception If the XML file cannot be loaded.
     */
    public function parse(string $filePath): SimpleXMLElement
    {
        if (! file_exists($filePath)) {
            throw new Exception("XML file not found: {$filePath}");
        }

        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            throw new Exception("Failed to load XML file: {$filePath}");
        }

        return $xml;
    }

    /**
     * Saves a SimpleXMLElement to a file.
     *
     * @param SimpleXMLElement $xml The XML element to save.
     * @param string $filePath The path where the XML should be saved.
     *
     * @throws Exception If the XML file cannot be saved.
     */
    public function save(SimpleXMLElement $xml, string $filePath): void
    {
        $result = $xml->asXML($filePath);
        if ($result === false) {
            throw new Exception("Failed to save XML file: {$filePath}");
        }
    }
}
