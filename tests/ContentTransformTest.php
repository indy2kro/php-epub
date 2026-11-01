<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use DOMDocument;
use PhpEpub\EpubFile;
use PhpEpub\InvalidEpubException;
use PhpEpub\Limits;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

/**
 * ContentManager::transform(): DOM edits over the XHTML documents.
 */
final class ContentTransformTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'content-transform';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    public function testWritesBackOnlyTheDocumentsThatChanged(): void
    {
        $epub = $this->open($this->book());
        $content = $epub->getContentManager();
        $navBefore = $content->getContent('EPUB/nav.xhtml');
        $seen = [];

        $changed = $content->transform(static function (DOMDocument $document, string $path) use (&$seen): bool {
            $seen[] = $path;
            $count = 0;
            foreach ($document->getElementsByTagName('p') as $paragraph) {
                if ($paragraph->getAttribute('class') === 'old') {
                    $paragraph->setAttribute('class', 'new');
                    $count++;
                }
            }

            return $count > 0;
        });

        $this->assertSame(['EPUB/text/two.xhtml'], $changed);
        $this->assertEqualsCanonicalizing(['EPUB/nav.xhtml', 'EPUB/text/chapter.xhtml', 'EPUB/text/two.xhtml'], $seen);
        $this->assertStringContainsString('<p class="new">Two</p>', $content->getContent('EPUB/text/two.xhtml'));
        $this->assertSame($navBefore, $content->getContent('EPUB/nav.xhtml'));
    }

    public function testCanBeLimitedToSomeDocuments(): void
    {
        $epub = $this->open($this->book());
        $seen = [];

        $changed = $epub->getContentManager()->transform(static function (DOMDocument $document, string $path) use (&$seen): bool {
            $seen[] = $path;
            $document->documentElement?->setAttribute('lang', 'fr');

            return true;
        }, ['EPUB/text/two.xhtml', 'EPUB/css/style.css']);

        $this->assertSame(['EPUB/text/two.xhtml'], $seen);
        $this->assertSame(['EPUB/text/two.xhtml'], $changed);
        $this->assertStringContainsString('lang="fr"', $epub->getContentManager()->getContent('EPUB/text/two.xhtml'));
    }

    public function testKeepsTheManifestPropertiesInStep(): void
    {
        $epub = $this->open($this->book());

        $epub->getContentManager()->transform(static function (DOMDocument $document, string $path): bool {
            if ($path !== 'EPUB/text/chapter.xhtml') {
                return false;
            }

            $script = $document->createElementNS('http://www.w3.org/1999/xhtml', 'script');
            $script->appendChild($document->createTextNode('void 0;'));
            $document->getElementsByTagName('body')->item(0)?->appendChild($script);

            return true;
        });

        $this->assertStringContainsString('scripted', (string) $epub->getManifest()->findByPath('EPUB/text/chapter.xhtml')?->properties);
    }

    public function testSkipsDocumentsThatAreNotWellFormed(): void
    {
        $epub = $this->open($this->book()->withFile('EPUB/text/two.xhtml', '<html><p>broken'));
        $seen = [];

        $epub->getContentManager()->transform(static function (DOMDocument $document, string $path) use (&$seen): bool {
            $seen[] = $path;

            return false;
        });

        $this->assertNotContains('EPUB/text/two.xhtml', $seen);
    }

    public function testRefusesDocumentsOverTheHtmlSizeLimit(): void
    {
        $path = $this->book()->buildEpub($this->tmpDir . '/limited.epub');
        $epub = EpubFile::open($path, limits: new Limits(maxHtmlBytes: 100));

        $this->expectException(InvalidEpubException::class);
        $epub->getContentManager()->transform(static fn (DOMDocument $document, string $path): bool => false);
    }

    private function open(EpubBuilder $builder): EpubFile
    {
        return EpubFile::open($builder->buildEpub($this->tmpDir . '/' . uniqid('book', true) . '.epub'));
    }

    private function book(): EpubBuilder
    {
        $opf = str_replace(
            '</manifest>',
            '<item id="two" href="text/two.xhtml" media-type="application/xhtml+xml"/></manifest>',
            (string) EpubBuilder::epub3()->getFile('EPUB/package.opf')
        );

        return EpubBuilder::epub3()
            ->withFile('EPUB/package.opf', $opf)
            ->withFile('EPUB/text/two.xhtml', EpubBuilder::xhtml('Two', '<p class="old">Two</p>'));
    }
}
