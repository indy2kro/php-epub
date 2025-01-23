<?php

declare(strict_types=1);

namespace PhpEpub;

class Parser
{
    public function __construct(private readonly XmlParser $xmlParser = new XmlParser())
    {
    }

    /**
     * Parse the EPUB file structure.
     *
     * @param string $directory The directory containing the extracted EPUB contents.
     */
    public function parse(string $directory): string
    {
        // Validate mimetype
        $this->validateMimetype($directory);

        $containerPath = $directory . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'container.xml';

        $opfPath = $this->extractOpfPath($containerPath);

        $this->validateOpf($directory . DIRECTORY_SEPARATOR . $opfPath);

        return $opfPath;
    }

    /**
     * Validates the mimetype file.
     */
    private function validateMimetype(string $directory): void
    {
        $mimetypePath = $directory . DIRECTORY_SEPARATOR . 'mimetype';

        if (! file_exists($mimetypePath)) {
            throw new Exception('Missing mimetype file: ' . $mimetypePath);
        }

        $mimetype = file_get_contents($mimetypePath);

        if ($mimetype === false || trim($mimetype) !== 'application/epub+zip') {
            throw new Exception('Invalid mimetype content: ' . $mimetypePath);
        }
    }

    /**
     * Extract the OPF path from container
     */
    private function extractOpfPath(string $containerPath): string
    {
        $xml = $this->xmlParser->parse($containerPath);

        $namespaces = $xml->getNamespaces(true);

        $containerNamespace = $namespaces[''] ?? null;

        if ($containerNamespace === null) {
            throw new Exception('No container namespace found in container.xml');
        }

        $xml->registerXPathNamespace('ns', $containerNamespace);

        $rootfiles = $xml->xpath('//ns:rootfile');

        if ($rootfiles === false || $rootfiles === null) {
            throw new Exception('No rootfile found in container.xml');
        }

        $rootfile = $rootfiles[0]; // Get the first rootfile node

        $opfPath = (string) $rootfile['full-path'];

        if ($opfPath === '') {
            throw new Exception('Missing full-path attribute in rootfile element');
        }

        return $opfPath;
    }

    /**
     * Validates the OPF file and checks for the presence of the NCX file.
     */
    private function validateOpf(string $opfPath): void
    {
        $xml = $this->xmlParser->parse($opfPath);

        $namespaces = $xml->getNamespaces(true);

        $containerNamespace = $namespaces[''] ?? null;

        if ($containerNamespace === null) {
            throw new Exception('No container namespace found in container.xml');
        }

        $xml->registerXPathNamespace('opf', $containerNamespace);

        $items = $xml->xpath('//opf:manifest/opf:item');

        if ($items === false || $items === null) {
            throw new Exception('Missing manifest in OPF file');
        }

        $ncxItem = null;
        foreach ($items as $item) {
            if ((string) $item['media-type'] === 'application/x-dtbncx+xml') {
                $ncxItem = (string) $item['href'];
                break;
            }
        }

        if ($ncxItem !== null) {
            $ncxPath = dirname($opfPath) . DIRECTORY_SEPARATOR . $ncxItem;
            $this->validateNcx($ncxPath);
        }
    }

    /**
     * Validates the NCX file.
     */
    private function validateNcx(string $ncxPath): void
    {
        $xml = $this->xmlParser->parse($ncxPath);

        $namespaces = $xml->getNamespaces(true);

        $navMap = $xml->children($namespaces[''])->navMap;

        if (! $navMap) {
            throw new Exception('Missing navMap in NCX file');
        }
    }
}
