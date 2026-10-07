<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\PathResolver;
use SimpleXMLElement;

class Parser
{
    private const string CONTAINER_NAMESPACE = 'urn:oasis:names:tc:opendocument:xmlns:container';

    private const string NCX_NAMESPACE = 'http://www.daisy.org/z3986/2005/ncx/';

    private const string PACKAGE_MEDIA_TYPE = 'application/oebps-package+xml';

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
            throw new InvalidEpubException('Missing mimetype file: ' . $mimetypePath);
        }

        $mimetype = FileSystemHelper::readFile($mimetypePath);

        if ($mimetype === null || trim($mimetype) !== 'application/epub+zip') {
            throw new InvalidEpubException('Invalid mimetype content: ' . $mimetypePath);
        }
    }

    /**
     * Extract the OPF path from container
     */
    private function extractOpfPath(string $containerPath): string
    {
        $xml = $this->xmlParser->parse($containerPath);

        $containerNamespace = $this->namespaceFor($xml, self::CONTAINER_NAMESPACE);
        if ($containerNamespace === null) {
            throw new InvalidEpubException('No container namespace found in container.xml');
        }

        // Namespace-aware, so prefixed containers (<c:container>) work as well.
        $xml->registerXPathNamespace('ns', $containerNamespace);

        $rootfiles = $xml->xpath('//ns:rootfile');

        if ($rootfiles === false || $rootfiles === null || $rootfiles === []) {
            throw new InvalidEpubException('No rootfile found in container.xml');
        }

        // A container may list other renditions (e.g. a PDF) before the package document.
        $rootfile = $rootfiles[0];
        foreach ($rootfiles as $candidate) {
            if ((string) $candidate['media-type'] === self::PACKAGE_MEDIA_TYPE) {
                $rootfile = $candidate;
                break;
            }
        }

        $opfPath = (string) $rootfile['full-path'];

        if ($opfPath === '') {
            throw new InvalidEpubException('Missing full-path attribute in rootfile element');
        }

        return $opfPath;
    }

    /**
     * Validates the OPF file and checks for the presence of the NCX file.
     */
    private function validateOpf(string $directory, string $opfPath): void
    {
        $xml = $this->xmlParser->parse($this->paths->resolve($directory, $opfPath));

        if (! $this->usesNamespace($xml, Metadata::OPF_NAMESPACE)) {
            throw new InvalidEpubException('No OPF namespace found in OPF file');
        }

        $xml->registerXPathNamespace('opf', Metadata::OPF_NAMESPACE);

        $manifest = $xml->xpath('/opf:package/opf:manifest');

        if ($manifest === false || $manifest === null || $manifest === []) {
            throw new InvalidEpubException('Missing manifest in OPF file');
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

        $ncxNamespace = $this->namespaceFor($xml, self::NCX_NAMESPACE);
        if ($ncxNamespace === null) {
            throw new InvalidEpubException('No NCX namespace found in NCX file');
        }

        $navMap = $xml->children($ncxNamespace)->navMap;

        if (! $navMap) {
            throw new InvalidEpubException('Missing navMap in NCX file');
        }
    }

    /**
     * Whether the document declares the namespace, as the default namespace or with any prefix.
     */
    private function usesNamespace(SimpleXMLElement $xml, string $namespace): bool
    {
        return in_array($namespace, $xml->getNamespaces(true), true);
    }

    /**
     * The expected namespace when the document declares it (with or without a prefix);
     * otherwise its default namespace, since books in the wild often get the URI slightly
     * wrong and loaded before; null when there is neither.
     */
    private function namespaceFor(SimpleXMLElement $xml, string $expected): ?string
    {
        if ($this->usesNamespace($xml, $expected)) {
            return $expected;
        }

        return $xml->getNamespaces(true)[''] ?? null;
    }
}
