<?php

declare(strict_types=1);

namespace PhpEpub;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\PathResolver;

/**
 * Obfuscated fonts (OCF "Font Obfuscation", listed in META-INF/encryption.xml) are XORed with
 * a key derived from the book's unique identifier, so they have to be re-keyed when it changes.
 * Two algorithms are in use: the IDPF one (EPUB 3) and Adobe's older one.
 *
 * @internal Used by EpubFile::save(), ContentManager and the converters.
 */
final readonly class FontObfuscation
{
    public const string IDPF = 'http://www.idpf.org/2008/embedding';

    public const string ADOBE = 'http://ns.adobe.com/pdf/enc#RC';

    private const string ENCRYPTION_NAMESPACE = 'http://www.w3.org/2001/04/xmlenc#';

    private const string EMPTY_ENCRYPTION = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#"/>';

    /**
     * How many leading bytes each algorithm obfuscates.
     */
    private const array OBFUSCATED_LENGTHS = [self::IDPF => 1040, self::ADOBE => 1024];

    public function __construct(
        private string $rootDirectory,
        private XmlParser $xmlParser = new XmlParser(),
        private PathResolver $paths = new PathResolver()
    ) {
    }

    /**
     * Re-keys every obfuscated font from one unique identifier to another. References that
     * point outside the book or to missing files are skipped, as are other encryption methods.
     *
     * @throws Exception If encryption.xml cannot be parsed, a font cannot be rewritten, or the new
     *                   identifier gives no key (Adobe's algorithm needs a urn:uuid identifier).
     */
    public function rekey(string $oldIdentifier, string $newIdentifier): void
    {
        foreach ($this->obfuscatedFonts() as $path => $algorithm) {
            $file = $this->paths->resolve($this->rootDirectory, $path);
            $font = FileSystemHelper::readFile($file);
            $oldKey = self::key($algorithm, $oldIdentifier);
            if ($font === null || $oldKey === null) {
                // Missing, or not decodable with the old identifier either: nothing to keep readable.
                continue;
            }

            $newKey = self::key($algorithm, $newIdentifier)
                ?? throw new Exception("The obfuscated font {$path} needs a urn:uuid unique identifier, not: {$newIdentifier}");

            @file_put_contents($file, self::apply(self::apply($font, $algorithm, $oldKey), $algorithm, $newKey)) !== false
                || throw new Exception("Failed to rewrite the obfuscated font: {$path}");
        }
    }

    /**
     * The obfuscation key for an identifier, or null when the identifier gives none.
     */
    public static function key(string $algorithm, string $identifier): ?string
    {
        if ($algorithm === self::IDPF) {
            return sha1(str_replace(["\x20", "\x09", "\x0D", "\x0A"], '', $identifier), true);
        }

        // Adobe: the 16 bytes of the identifier's UUID.
        $hex = str_replace('-', '', (string) preg_replace('/^urn:uuid:/i', '', trim($identifier)));

        return preg_match('/^[0-9a-f]{32}$/i', $hex) === 1 ? (string) hex2bin($hex) : null;
    }

    /**
     * Whether an algorithm is a font obfuscation (IDPF or Adobe), as opposed to real encryption.
     */
    public static function isObfuscation(string $algorithm): bool
    {
        return isset(self::OBFUSCATED_LENGTHS[$algorithm]);
    }

    /**
     * Obfuscates a font or, as XOR is its own inverse, de-obfuscates it: the leading bytes
     * (1040 for IDPF, 1024 for Adobe) are XORed with the key; the rest is unchanged.
     *
     * @param string $key The key from key().
     */
    public static function apply(string $font, string $algorithm, string $key): string
    {
        $length = min(self::OBFUSCATED_LENGTHS[$algorithm] ?? 0, strlen($font));

        return (substr($font, 0, $length) ^ self::keyStream($key, $length)) . substr($font, $length);
    }

    /**
     * Book-relative paths of the fonts obfuscated with a known algorithm, inside the book.
     *
     * @return array<string, string> path => algorithm
     *
     * @throws XmlException If encryption.xml cannot be parsed.
     */
    public function obfuscatedFonts(): array
    {
        $fonts = [];
        foreach ((new Encryption($this->rootDirectory, $this->xmlParser, $this->paths))->entries() as $entry) {
            if (self::isObfuscation($entry['algorithm']) && $this->insideBook($entry['uri'])) {
                $fonts[$this->paths->normalize($entry['uri'])] = $entry['algorithm'];
            }
        }

        return $fonts;
    }

    /**
     * Lists a font in encryption.xml (creating the file when the book has none) with an obfuscation
     * algorithm, or, with null, removes its entry so that the font reads as plain.
     *
     * @throws Exception If the path leaves the book, or encryption.xml cannot be parsed or written.
     */
    public function setAlgorithm(string $path, ?string $algorithm): void
    {
        $path = $this->paths->normalize($path);
        $file = $this->rootDirectory . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'encryption.xml';
        $exists = is_file($file);
        if (! $exists && $algorithm === null) {
            return;
        }

        $xml = $exists ? $this->xmlParser->parse($file) : $this->xmlParser->parseString(self::EMPTY_ENCRYPTION, $file);
        $root = dom_import_simplexml($xml);
        $document = $root->ownerDocument ?? throw new Exception("Failed to update: {$file}");
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('enc', self::ENCRYPTION_NAMESPACE);

        $found = false;
        foreach (iterator_to_array($xpath->query('//enc:EncryptedData') ?: []) as $data) {
            if (! $data instanceof DOMElement) {
                continue;
            }

            $reference = $this->firstElement($xpath, 'enc:CipherData/enc:CipherReference', $data);
            if (! $reference instanceof DOMElement || ! $this->refersTo($reference->getAttribute('URI'), $path)) {
                continue;
            }

            $found = true;
            if ($algorithm === null) {
                $data->parentNode?->removeChild($data);
            } else {
                $this->methodOf($document, $xpath, $data)->setAttribute('Algorithm', $algorithm);
            }
        }

        if (! $found && $algorithm !== null) {
            $root->appendChild($this->encryptedData($document, $path, $algorithm));
        }

        $this->xmlParser->save($xml, $file);
    }

    private function firstElement(DOMXPath $xpath, string $expression, DOMElement $context): ?DOMElement
    {
        foreach ($xpath->query($expression, $context) ?: [] as $node) {
            return $node instanceof DOMElement ? $node : null;
        }

        return null;
    }

    private function refersTo(string $uri, string $path): bool
    {
        try {
            return $this->paths->normalize(rawurldecode($uri)) === $path;
        } catch (InvalidEpubException) {
            return false;
        }
    }

    /**
     * The EncryptionMethod element of an EncryptedData one, created when it is missing.
     */
    private function methodOf(DOMDocument $document, DOMXPath $xpath, DOMElement $data): DOMElement
    {
        $method = $this->firstElement($xpath, 'enc:EncryptionMethod', $data);
        if ($method instanceof DOMElement) {
            return $method;
        }

        $method = $document->createElementNS(self::ENCRYPTION_NAMESPACE, 'enc:EncryptionMethod');
        $data->insertBefore($method, $data->firstChild);

        return $method;
    }

    private function encryptedData(DOMDocument $document, string $path, string $algorithm): DOMElement
    {
        $uri = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        $method = $document->createElementNS(self::ENCRYPTION_NAMESPACE, 'enc:EncryptionMethod');
        $method->setAttribute('Algorithm', $algorithm);
        $reference = $document->createElementNS(self::ENCRYPTION_NAMESPACE, 'enc:CipherReference');
        $reference->setAttribute('URI', $uri);
        $cipherData = $document->createElementNS(self::ENCRYPTION_NAMESPACE, 'enc:CipherData');
        $cipherData->appendChild($reference);

        $data = $document->createElementNS(self::ENCRYPTION_NAMESPACE, 'enc:EncryptedData');
        $data->appendChild($method);
        $data->appendChild($cipherData);

        return $data;
    }

    private function insideBook(string $path): bool
    {
        try {
            $this->paths->resolve($this->rootDirectory, $path);

            return true;
        } catch (InvalidEpubException) {
            return false;
        }
    }

    private static function keyStream(string $key, int $length): string
    {
        return substr(str_repeat($key, intdiv($length, strlen($key)) + 1), 0, $length);
    }
}
