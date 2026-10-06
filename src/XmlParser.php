<?php

declare(strict_types=1);

namespace PhpEpub;

use SimpleXMLElement;

class XmlParser
{
    /**
     * Loads an XML file and returns a SimpleXMLElement.
     *
     * Documents come from untrusted books, so entity declarations are rejected
     * and the parser never touches the network (DOCTYPE references are not fetched).
     *
     * @param string $filePath The path to the XML file.
     *
     * @throws XmlException If the XML file cannot be loaded.
     */
    public function parse(string $filePath): SimpleXMLElement
    {
        if (! file_exists($filePath)) {
            throw new XmlException("XML file not found: {$filePath}");
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new XmlException("Failed to read XML file: {$filePath}");
        }

        // Entity declarations enable expansion attacks; EPUB container, package and NCX files never need them.
        if (preg_match('/<!ENTITY/i', $content) === 1) {
            throw new XmlException("XML entity declarations are not allowed: {$filePath}");
        }

        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                $errors = libxml_get_errors();
                $detail = $errors === [] ? '' : sprintf(' (%s at line %d)', trim($errors[0]->message), $errors[0]->line);

                throw new XmlException("Failed to load XML file: {$filePath}{$detail}");
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }
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
