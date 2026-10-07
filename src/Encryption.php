<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\PathResolver;

/**
 * What a book declares in META-INF/encryption.xml, and whether it is DRM-protected: some
 * resource is encrypted with an algorithm that is not a font obfuscation, or the book has an
 * Adobe ADEPT rights.xml or a Readium LCP license.lcpl. Detection only: nothing is decrypted.
 *
 * @internal Used by EpubFile, Validator, FontObfuscation and the converters.
 */
final readonly class Encryption
{
    private const string ENCRYPTION_NAMESPACE = 'http://www.w3.org/2001/04/xmlenc#';

    /**
     * Files that only DRM-protected books have (Adobe ADEPT, Readium LCP).
     */
    private const array DRM_FILES = ['rights.xml', 'license.lcpl'];

    public function __construct(
        private string $rootDirectory,
        private XmlParser $xmlParser = new XmlParser(),
        private PathResolver $paths = new PathResolver()
    ) {
    }

    /**
     * The resources encryption.xml lists, as [URI (percent-decoded, relative to the book root), algorithm].
     * An empty list when the book has no encryption.xml.
     *
     * @return list<array{uri: string, algorithm: string}>
     *
     * @throws XmlException If encryption.xml cannot be parsed.
     */
    public function entries(): array
    {
        $file = $this->rootDirectory . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'encryption.xml';
        if (! is_file($file)) {
            return [];
        }

        $encryption = $this->xmlParser->parse($file);
        $encryption->registerXPathNamespace('enc', self::ENCRYPTION_NAMESPACE);

        $entries = [];
        foreach ($encryption->xpath('//enc:EncryptedData') ?: [] as $data) {
            $data->registerXPathNamespace('enc', self::ENCRYPTION_NAMESPACE);
            $method = ($data->xpath('enc:EncryptionMethod') ?: [])[0] ?? null;
            $reference = ($data->xpath('enc:CipherData/enc:CipherReference') ?: [])[0] ?? null;
            // CipherReference URIs are relative to the root of the container.
            $entries[] = [
                'uri' => rawurldecode((string) ($reference['URI'] ?? '')),
                'algorithm' => (string) ($method['Algorithm'] ?? ''),
            ];
        }

        return $entries;
    }

    /**
     * Whether the book is DRM-protected. An encryption.xml that cannot be parsed counts as
     * listing nothing, so a damaged file never stops a book from being used.
     */
    public function isDrmProtected(): bool
    {
        foreach (self::DRM_FILES as $file) {
            if (is_file($this->rootDirectory . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . $file)) {
                return true;
            }
        }

        foreach ($this->safeEntries() as $entry) {
            if (! FontObfuscation::isObfuscation($entry['algorithm'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Book-relative paths (sorted, inside the book) of the resources encrypted with an algorithm
     * that is not a font obfuscation.
     *
     * @return list<string>
     */
    public function encryptedPaths(): array
    {
        $paths = [];
        foreach ($this->safeEntries() as $entry) {
            if (FontObfuscation::isObfuscation($entry['algorithm'])) {
                continue;
            }

            try {
                $paths[] = $this->paths->normalize($entry['uri']);
            } catch (InvalidEpubException) {
                // A reference outside the book names nothing in it.
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @throws ConversionException If the book is DRM-protected.
     */
    public function assertNotDrmProtected(): void
    {
        $this->isDrmProtected() && throw new ConversionException(
            'The book is DRM-protected, so its content cannot be read and it cannot be converted.'
        );
    }

    /**
     * @return list<array{uri: string, algorithm: string}>
     */
    private function safeEntries(): array
    {
        try {
            return $this->entries();
        } catch (XmlException) {
            return [];
        }
    }
}
