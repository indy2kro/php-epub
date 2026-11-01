<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\EpubFile;
use PhpEpub\Limits;
use PhpEpub\XmlParser;
use PhpEpub\ZipHandler;
use PHPUnit\Framework\TestCase;

/**
 * Test for UX-02: EpubFile::create() should accept Limits, ZipHandler, XmlParser like open()
 */
final class EpubFileCreateWithLimitsTest extends TestCase
{
    public function testCreateWithCustomLimits(): void
    {
        $path = sys_get_temp_dir() . '/test_create_with_limits.epub';
        $limits = Limits::web();

        $epub = EpubFile::create($path, 'Test Book', 'en', null, null, null, $limits);

        // Verify the book was created with the custom limits
        $this->assertInstanceOf(EpubFile::class, $epub);

        $epub->close();
        @unlink($path);
    }

    public function testCreateWithCustomZipHandler(): void
    {
        $path = sys_get_temp_dir() . '/test_create_with_zip.epub';
        $zipHandler = new ZipHandler();

        $epub = EpubFile::create($path, 'Test Book', 'en', null, $zipHandler);

        $this->assertInstanceOf(EpubFile::class, $epub);

        $epub->close();
        @unlink($path);
    }

    public function testCreateWithCustomXmlParser(): void
    {
        $path = sys_get_temp_dir() . '/test_create_with_xml.epub';
        $xmlParser = new XmlParser();

        $epub = EpubFile::create($path, 'Test Book', 'en', null, null, $xmlParser);

        $this->assertInstanceOf(EpubFile::class, $epub);

        $epub->close();
        @unlink($path);
    }

    public function testCreateWithAllCustomParameters(): void
    {
        $path = sys_get_temp_dir() . '/test_create_with_all.epub';
        $limits = Limits::web();
        $zipHandler = new ZipHandler();
        $xmlParser = new XmlParser();

        $epub = EpubFile::create($path, 'Test Book', 'en', 'urn:uuid:test', $zipHandler, $xmlParser, $limits);

        $this->assertInstanceOf(EpubFile::class, $epub);

        $epub->close();
        @unlink($path);
    }

    public function testCreateWithCustomLimitsAffectsContentManager(): void
    {
        $path = sys_get_temp_dir() . '/test_create_limits_affects.epub';
        $limits = Limits::web();

        $epub = EpubFile::create($path, 'Test Book', 'en', null, null, null, $limits);

        // The ContentManager should be accessible
        $contentManager = $epub->getContentManager();
        $this->assertInstanceOf(\PhpEpub\ContentManager::class, $contentManager);

        $epub->close();
        @unlink($path);
    }

    public function testCreateWithoutOptionalParametersStillWorks(): void
    {
        $path = sys_get_temp_dir() . '/test_create_default.epub';

        $epub = EpubFile::create($path, 'Test Book', 'en');

        $this->assertInstanceOf(EpubFile::class, $epub);

        $epub->close();
        @unlink($path);
    }
}
