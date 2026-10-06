<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\PathResolver;

class Parser
{
    public function __construct(
        private readonly XmlParser $xmlParser = new XmlParser(),
        private readonly PathResolver $paths = new PathResolver()
    ) {
    }

    /**
     * Parse the EPUB file structure.
     *
     * @param string $directory The directory containing the extracted EPUB contents.
     *
     * @return string The OPF path relative to $directory, normalized with "/" separators.
     */
    public function parse(string $directory): string
    {
        // Validate mimetype
        $this->validateMimetype($directory);

        $containerPath = $directory . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'container.xml';

        $opfPath = $this->paths->normalize($this->extractOpfPath($containerPath));

        $this->validateOpf($directory, $opfPath);

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

        if ($rootfiles === false || $rootfiles === null || $rootfiles === []) {
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
    private function validateOpf(string $directory, string $opfPath): void
    {
        $xml = $this->xmlParser->parse($this->paths->resolve($directory, $opfPath));

        $namespaces = $xml->getNamespaces(true);

        $opfNamespace = $namespaces[''] ?? null;

        if ($opfNamespace === null) {
            throw new Exception('No OPF namespace found in OPF file');
        }

        $xml->registerXPathNamespace('opf', $opfNamespace);

        $manifest = $xml->xpath('/opf:package/opf:manifest');

        if ($manifest === false || $manifest === null || $manifest === []) {
            throw new Exception('Missing manifest in OPF file');
        }

        $items = $xml->xpath('/opf:package/opf:manifest/opf:item') ?: [];

        $ncxItem = null;
        foreach ($items as $item) {
            if ((string) $item['media-type'] === 'application/x-dtbncx+xml') {
                $ncxItem = (string) $item['href'];
                break;
            }
        }

        if ($ncxItem !== null) {
            // Manifest hrefs are URLs relative to the OPF file.
            $opfDirectory = dirname($opfPath);
            $ncxPath = ($opfDirectory === '.' ? '' : $opfDirectory . '/') . rawurldecode($ncxItem);
            $this->validateNcx($this->paths->resolve($directory, $ncxPath));
        }
    }

    /**
     * Validates the NCX file.
     */
    private function validateNcx(string $ncxPath): void
    {
        $xml = $this->xmlParser->parse($ncxPath);

        $namespaces = $xml->getNamespaces(true);

        if (! isset($namespaces[''])) {
            throw new Exception('No NCX namespace found in NCX file');
        }

        $navMap = $xml->children($namespaces[''])->navMap;

        if (! $navMap) {
            throw new Exception('Missing navMap in NCX file');
        }
    }
}
