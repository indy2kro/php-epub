<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    private string $validEpubDir;
    private string $invalidEpubDir;
    private string $missingFilesEpubDir;

    protected function setUp(): void
    {
        $this->validEpubDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'valid_epub';
        $this->invalidEpubDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'invalid_epub';
        $this->missingFilesEpubDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'missing_files_epub';

        // Ensure the directories exist and are set up correctly
        if (!is_dir($this->validEpubDir)) {
            mkdir($this->validEpubDir, 0777, true);
            file_put_contents($this->validEpubDir . DIRECTORY_SEPARATOR . 'mimetype', 'application/epub+zip');
            mkdir($this->validEpubDir . DIRECTORY_SEPARATOR . 'META-INF', 0777, true);
            file_put_contents($this->validEpubDir . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'container.xml', '<container></container>');
        }

        if (!is_dir($this->invalidEpubDir)) {
            mkdir($this->invalidEpubDir, 0777, true);
            file_put_contents($this->invalidEpubDir . DIRECTORY_SEPARATOR . 'mimetype', 'invalid/mimetype');
            mkdir($this->invalidEpubDir . DIRECTORY_SEPARATOR . 'META-INF', 0777, true);
            file_put_contents($this->invalidEpubDir . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'container.xml', '<container></container>');
        }

        if (!is_dir($this->missingFilesEpubDir)) {
            mkdir($this->missingFilesEpubDir, 0777, true);
            // Intentionally leave out required files
        }
        
        $this->markTestSkipped();
    }

    protected function tearDown(): void
    {
        // Clean up any directories created during tests
        $this->deleteDirectory($this->validEpubDir);
        $this->deleteDirectory($this->invalidEpubDir);
        $this->deleteDirectory($this->missingFilesEpubDir);
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = scandir($dir);

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($filePath) ? $this->deleteDirectory($filePath) : unlink($filePath);
        }
        rmdir($dir);
    }

    public function testValidEpub(): void
    {
        $validator = new Validator();
        $isValid = $validator->isValid($this->validEpubDir);
        $this->assertTrue($isValid);
        $this->assertEmpty($validator->getErrors());
    }

    public function testInvalidEpub(): void
    {
        $validator = new Validator();
        $isValid = $validator->isValid($this->invalidEpubDir);
        $this->assertFalse($isValid);
        $this->assertNotEmpty($validator->getErrors());
        $this->assertContains('Invalid mimetype content', $validator->getErrors());
    }

    public function testMissingFilesEpub(): void
    {
        $validator = new Validator();
        $isValid = $validator->isValid($this->missingFilesEpubDir);
        $this->assertFalse($isValid);
        $this->assertNotEmpty($validator->getErrors());
        $this->assertContains('Missing META-INF/container.xml', $validator->getErrors());
        $this->assertContains('Missing mimetype file', $validator->getErrors());
    }
}
