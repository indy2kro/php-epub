<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\PathResolver;
use SimpleXMLElement;

class Parser
{
    private const string CONTAINER_NAMESPACE = 'urn:oasis:names:tc:opendocument:xmlns:container';

    private const string PACKAGE_MEDIA_TYPE = 'application/oebps-package+xml';

    public function __construct(
        private readonly XmlParser $xmlParser = new XmlParser(),
        private readonly PathResolver $paths = new PathResolver()
    ) {
    }

    /**
     * Locates the package document (OPF) of an extracted book and checks that it can be read.
     *
     * Only problems that make the book unreadable throw: no container, no rootfile, an OPF that is
     * not XML or has no manifest. A missing or wrong mimetype and a broken NCX, which reading
     * systems tolerate, are reported by EpubFile::validate() instead.
     *
     * @param string $directory The directory containing the extracted EPUB contents.
     *
     * @return string The OPF path relative to $directory, normalized with "/" separators.
     *
     * @throws InvalidEpubException If the book has no readable package document.
     */
    public function parse(string $directory): string
    {
        $containerPath = $directory . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'container.xml';

        $opfPath = $this->locatePackage($this->xmlParser->parse($containerPath));

        $this->assertPackage($this->xmlParser->parse($this->paths->resolve($directory, $opfPath)));

        return $opfPath;
    }

    /**
     * The path of the package document named by a parsed META-INF/container.xml, normalized with "/" separators.
     *
     * @throws InvalidEpubException If the container names no package document or a path outside the book.
     */
    public function locatePackage(SimpleXMLElement $container): string
    {
        return $this->paths->normalize($this->extractOpfPath($container));
    }

    /**
     * Checks that a parsed package document has the OPF namespace and a manifest.
     *
     * @throws InvalidEpubException If it has not.
     */
    public function assertPackage(SimpleXMLElement $xml): void
    {
        if (! $this->usesNamespace($xml, Metadata::OPF_NAMESPACE)) {
            throw new InvalidEpubException('No OPF namespace found in OPF file');
        }

        $xml->registerXPathNamespace('opf', Metadata::OPF_NAMESPACE);

        $manifest = $xml->xpath('/opf:package/opf:manifest');

        if ($manifest === false || $manifest === null || $manifest === []) {
            throw new InvalidEpubException('Missing manifest in OPF file');
        }
    }

    /**
     * Extract the OPF path from container
     */
    private function extractOpfPath(SimpleXMLElement $xml): string
    {

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
