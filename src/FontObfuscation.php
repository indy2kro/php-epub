<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\PathResolver;
use SimpleXMLElement;

/**
 * Obfuscated fonts (OCF "Font Obfuscation", listed in META-INF/encryption.xml) are XORed with
 * a key derived from the book's unique identifier, so they have to be re-keyed when it changes.
 * Two algorithms are in use: the IDPF one (EPUB 3) and Adobe's older one.
 *
 * @internal Used by EpubFile::save().
 */
final readonly class FontObfuscation
{
    public const string IDPF = 'http://www.idpf.org/2008/embedding';

    public const string ADOBE = 'http://ns.adobe.com/pdf/enc#RC';

    private const string ENCRYPTION_NAMESPACE = 'http://www.w3.org/2001/04/xmlenc#';

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

            $length = min(self::OBFUSCATED_LENGTHS[$algorithm], strlen($font));
            $header = substr($font, 0, $length) ^ self::keyStream($oldKey, $length) ^ self::keyStream($newKey, $length);
            @file_put_contents($file, $header . substr($font, $length)) !== false
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
     * Book-relative paths of the fonts obfuscated with a known algorithm, inside the book.
     *
     * @return array<string, string> path => algorithm
     *
     * @throws Exception If encryption.xml cannot be parsed.
     */
    private function obfuscatedFonts(): array
    {
        $file = $this->rootDirectory . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'encryption.xml';
        if (! is_file($file)) {
            return [];
        }

        $encryption = $this->xmlParser->parse($file);
        $encryption->registerXPathNamespace('enc', self::ENCRYPTION_NAMESPACE);

        $fonts = [];
        foreach ($encryption->xpath('//enc:EncryptedData') ?: [] as $data) {
            $data->registerXPathNamespace('enc', self::ENCRYPTION_NAMESPACE);
            $algorithm = (string) ($this->first($data, 'enc:EncryptionMethod')['Algorithm'] ?? '');
            // CipherReference URIs are relative to the root of the container.
            $uri = rawurldecode((string) ($this->first($data, 'enc:CipherData/enc:CipherReference')['URI'] ?? ''));

            if (isset(self::OBFUSCATED_LENGTHS[$algorithm]) && $this->insideBook($uri)) {
                $fonts[$this->paths->normalize($uri)] = $algorithm;
            }
        }

        return $fonts;
    }

    private function first(SimpleXMLElement $context, string $expression): ?SimpleXMLElement
    {
        return ($context->xpath($expression) ?: [])[0] ?? null;
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
