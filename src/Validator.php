<?php

declare(strict_types=1);

namespace PhpEpub;

class Validator
{
    /**
     * @var array<string> List of validation errors.
     */
    private array $errors = [];

    /**
     * Validates the EPUB file structure.
     *
     * @param string $directory The directory containing the extracted EPUB contents.
     *
     * @return bool True if the EPUB is valid, false otherwise.
     */
    public function isValid(string $directory): bool
    {
        $this->errors = []; // Reset errors

        // Validate mimetype
        $this->validateMimetype($directory);

        // Validate container.xml
        $containerPath = $directory . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'container.xml';
        if (! file_exists($containerPath)) {
            $this->errors[] = 'Missing META-INF/container.xml';
        } else {
            $opfPath = $this->validateContainer($containerPath);
            if ($opfPath !== null) {
                $this->validateOpf($directory . DIRECTORY_SEPARATOR . $opfPath);
            }
        }

        return $this->errors === [];
    }

    /**
     * Returns a list of validation errors.
     *
     * @return array<string> The list of validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Validates the mimetype file.
     */
    private function validateMimetype(string $directory): void
    {
        $mimetypePath = $directory . DIRECTORY_SEPARATOR . 'mimetype';
        if (! file_exists($mimetypePath)) {
            $this->errors[] = 'Missing mimetype file';
        } else {
            $mimetype = file_get_contents($mimetypePath);
            if ($mimetype === false || trim($mimetype) !== 'application/epub+zip') {
                $this->errors[] = 'Invalid mimetype content';
            }
        }
    }

    /**
     * Validates the container.xml file and returns the OPF path if valid.
     *
     * @return string|null The OPF path if found, null otherwise.
     */
    private function validateContainer(string $containerPath): ?string
    {
        $xml = simplexml_load_file($containerPath);
        if ($xml === false) {
            $this->errors[] = 'Invalid XML in container.xml';
            return null;
        }

        $namespaces = $xml->getNamespaces(true);
        $rootfiles = $xml->children($namespaces[''])->rootfiles;
        if (! $rootfiles || ! $rootfiles->rootfile) {
            $this->errors[] = 'No rootfile found in container.xml';
            return null;
        }

        $opfPath = (string) $rootfiles->rootfile['full-path'];
        if ($opfPath === '') {
            $this->errors[] = 'Missing full-path attribute in rootfile element';
            return null;
        }

        return $opfPath;
    }

    /**
     * Validates the OPF file and checks for the presence of the NCX file.
     */
    private function validateOpf(string $opfPath): void
    {
        if (! file_exists($opfPath)) {
            $this->errors[] = "OPF file not found at path: {$opfPath}";
            return;
        }

        $xml = simplexml_load_file($opfPath);
        if ($xml === false) {
            $this->errors[] = 'Invalid XML in OPF file';
            return;
        }

        $namespaces = $xml->getNamespaces(true);
        $manifest = $xml->children($namespaces[''])->manifest;
        if (! $manifest) {
            $this->errors[] = 'Missing manifest in OPF file';
            return;
        }

        $ncxItem = null;
        foreach ($manifest->item as $item) {
            if ((string) $item['media-type'] === 'application/x-dtbncx+xml') {
                $ncxItem = (string) $item['href'];
                break;
            }
        }

        if ($ncxItem === null) {
            $this->errors[] = 'NCX file not referenced in OPF manifest';
        } else {
            $ncxPath = dirname($opfPath) . DIRECTORY_SEPARATOR . $ncxItem;
            $this->validateNcx($ncxPath);
        }
    }

    /**
     * Validates the NCX file.
     */
    private function validateNcx(string $ncxPath): void
    {
        if (! file_exists($ncxPath)) {
            $this->errors[] = "NCX file not found at path: {$ncxPath}";
            return;
        }

        $xml = simplexml_load_file($ncxPath);
        if ($xml === false) {
            $this->errors[] = 'Invalid XML in NCX file';
            return;
        }

        $namespaces = $xml->getNamespaces(true);
        $navMap = $xml->children($namespaces[''])->navMap;
        if (! $navMap) {
            $this->errors[] = 'Missing navMap in NCX file';
        }
    }
}
