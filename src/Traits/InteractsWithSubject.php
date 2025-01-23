<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use SimpleXMLElement;

trait InteractsWithSubject
{
    private readonly SimpleXMLElement $opfXml;
    private readonly string $dcNamespace;

    /**
     * Gets the subject of the EPUB.
     */
    public function getSubject(): string
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $subjectNode = $this->opfXml->xpath('//dc:subject');

        if ($subjectNode === false || $subjectNode === null || $subjectNode === []) {
            return '';
        }

        return (string) $subjectNode[0];
    }

    /**
     * Sets the subject of the EPUB.
     */
    public function setSubject(string $subject): void
    {
        $this->opfXml->registerXPathNamespace('dc', $this->dcNamespace);

        $subjectNode = $this->opfXml->xpath('//dc:subject');

        if ($subjectNode !== false && $subjectNode !== null && $subjectNode !== [] && isset($subjectNode[0])) {
            // @phpstan-ignore-next-line
            $subjectNode[0][0] = $subject;
        } else {
            $this->opfXml->metadata->addChild('subject', $subject, $this->dcNamespace);
        }
    }
}
