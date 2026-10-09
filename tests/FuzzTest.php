<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use ErrorException;
use PhpEpub\Converters\EpubDocumentLoader;
use PhpEpub\EpubFile;
use PhpEpub\EpubReader;
use PhpEpub\Exception;
use PhpEpub\Limits;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\XmlParser;
use PhpEpub\ZipHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;
use ZipArchive;

/**
 * Feeds mutated books to the parsers of untrusted input. The property: a mutated book either works or raises a
 * PhpEpub\Exception; it never raises another exception (TypeError, ValueError, ...) nor a PHP warning, notice or
 * deprecation, which are turned into exceptions here.
 *
 * Environment variables:
 *   EPUB_FUZZ_SEED        base seed (default 20261007); a failure prints it
 *   EPUB_FUZZ_ITERATIONS  number of mutated books (default 40, a few seconds; the nightly CI job uses thousands)
 *   EPUB_FUZZ_FIRST       first iteration (default 0); with the seed, reproduces one failing iteration
 *
 * Every iteration derives its own random generator from seed + iteration number, so a failure is reproduced with
 * EPUB_FUZZ_SEED=<seed> EPUB_FUZZ_FIRST=<iteration> EPUB_FUZZ_ITERATIONS=1 vendor/bin/phpunit --group fuzz
 */
#[Group('fuzz')]
final class FuzzTest extends TestCase
{
    private const int DEFAULT_SEED = 20261007;

    private const int DEFAULT_ITERATIONS = 40;

    /**
     * Pieces inserted into XML: entities, DOCTYPEs, odd encodings, deep nesting, control characters.
     */
    private const array SNIPPETS = [
        '<!DOCTYPE x [<!ENTITY a "b">]>',
        '<!DOCTYPE x [<!ENTITY % p SYSTEM "file:///etc/passwd">%p;]>',
        '&a;',
        '&#0;',
        '&#x110000;',
        '&#xD800;',
        '<![CDATA[',
        ']]>',
        '<?xml version="9.9" encoding="nope"?>',
        '<?xml version="1.0" encoding="UTF-16"?>',
        "\0",
        "\xEF\xBB\xBF",
        "\xFF\xFE",
        "\xC3\x28",
        '<a><a><a><a><a><a><a><a><a><a><a><a><a><a><a><a>',
        '</a></a></a>',
        'xmlns="',
        '../',
        '..\\',
        'href="',
        '<item id="x" href="../../../../etc/passwd" media-type="text/css"/>',
        '<itemref idref="missing"/>',
    ];

    /**
     * Names an archive entry may be renamed to; all are cut or repeated to the length of the original name.
     */
    private const array ODD_NAMES = [
        '../evil',
        '/abs/path',
        '..\\evil',
        'C:/evil',
        'a//b',
        './a/./b',
        'a/../../b',
        "a\0b",
        'dir/',
        '.',
        '...',
        'META-INF/../container.xml',
        'CON',
        "a\nb",
        '%2e%2e/x',
    ];

    private string $workDir;

    private int $seed;

    private int $iterations;

    private int $first;

    /**
     * @var list<string> raw archives to mutate
     */
    private array $bases = [];

    protected function setUp(): void
    {
        $this->seed = (int) (getenv('EPUB_FUZZ_SEED') ?: self::DEFAULT_SEED);
        $this->iterations = max(1, (int) (getenv('EPUB_FUZZ_ITERATIONS') ?: self::DEFAULT_ITERATIONS));
        $this->first = max(0, (int) (getenv('EPUB_FUZZ_FIRST') ?: 0));

        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epub_fuzz_' . bin2hex(random_bytes(8));
        mkdir($this->workDir, 0700, true);

        $this->bases = [
            $this->archive(EpubBuilder::epub3()),
            $this->archive(EpubBuilder::epub2()),
        ];
        foreach (glob(__DIR__ . '/fixtures/valid*.epub') ?: [] as $fixture) {
            $this->bases[] = (string) file_get_contents($fixture);
        }
    }

    protected function tearDown(): void
    {
        (new FileSystemHelper())->deleteDirectory($this->workDir);
    }

    public function testMutatedBooksOnlyRaiseLibraryExceptions(): void
    {
        $this->fuzz(function (Randomizer $random, int $iteration): array {
            [$strategy, $bytes] = $this->mutatedArchive($random);
            $book = $this->workDir . DIRECTORY_SEPARATOR . 'book.epub';
            file_put_contents($book, $bytes);

            $this->guard(fn () => $this->exercise($book, $iteration));
            unlink($book);

            return [$strategy, $bytes];
        });
    }

    public function testMutatedXmlOnlyRaisesLibraryExceptions(): void
    {
        $this->fuzz(function (Randomizer $random): array {
            $samples = self::xmlSamples();
            $xml = array_keys($samples)[$random->getInt(0, count($samples) - 1)];
            $mutated = $this->mutate($samples[$xml], $random);

            $this->guard(function () use ($mutated): void {
                $parser = new XmlParser();
                $parser->parseString($mutated);
            });

            return ['parseString of ' . $xml, $mutated];
        });
    }

    /**
     * Runs the iterations with the error handler installed and reports a failure with everything needed to reproduce it.
     *
     * @param callable(Randomizer, int): array{string, string} $iteration returns the strategy used and the input
     */
    private function fuzz(callable $iteration): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        $strategy = '';
        $bytes = '';
        $current = $this->first;
        try {
            for ($current = $this->first; $current < $this->first + $this->iterations; ++$current) {
                $random = new Randomizer(new Mt19937($this->seed + $current));
                [$strategy, $bytes] = $iteration($random, $current);
            }
        } catch (Throwable $e) {
            $saved = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sprintf('epub-fuzz-%d-%d.bin', $this->seed, $current);
            file_put_contents($saved, $bytes);
            $this->fail(sprintf(
                "Fuzz failure with seed %d at iteration %d (%s): %s: %s in %s:%d\nReproduce: EPUB_FUZZ_SEED=%d EPUB_FUZZ_FIRST=%d EPUB_FUZZ_ITERATIONS=1 vendor/bin/phpunit --group fuzz\nInput saved to %s",
                $this->seed,
                $current,
                $strategy,
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $this->seed,
                $current,
                $saved
            ));
        } finally {
            restore_error_handler();
        }

        $this->assertSame($this->first + $this->iterations, $current, 'Not every iteration ran.');
    }

    /**
     * Runs $action; an exception of the library is an acceptable outcome, anything else propagates.
     */
    private function guard(callable $action): void
    {
        try {
            $action();
        } catch (Exception) {
            // Rejecting a bad book is the expected behaviour.
        }
    }

    /**
     * Runs every untrusted-input entry point on one archive.
     */
    private function exercise(string $book, int $iteration): void
    {
        $extracted = $this->workDir . DIRECTORY_SEPARATOR . 'extracted_' . $iteration;
        try {
            $this->guard(function () use ($book, $extracted): void {
                (new ZipHandler())->extract($book, $extracted);
                (new EpubDocumentLoader())->load($extracted);
            });

            $this->guard(function () use ($book): void {
                $epubFile = EpubFile::open($book);
                try {
                    $epubFile->validate();
                    $epubFile->getMetadata()->getTitle();
                    $epubFile->getSpine()->getItems();
                    $epubFile->getTableOfContents()->getEntries();
                } finally {
                    $epubFile->cleanup();
                }
            });

            // The read-only reader reads entries lazily, so it is the entry point that meets damaged entries mid-read.
            $this->guard(function () use ($book): void {
                $reader = EpubReader::open($book, Limits::web());
                try {
                    $reader->getMetadata()->getTitle();
                    $reader->getSpine()->getItems();
                    $reader->getTableOfContents()->getEntries();
                    $reader->getCoverImage();
                    $reader->getText(false);
                } finally {
                    $reader->close();
                }
            });
        } finally {
            (new FileSystemHelper())->deleteDirectory($extracted);
        }
    }

    /**
     * @return array{string, string} the strategy and the mutated archive
     */
    private function mutatedArchive(Randomizer $random): array
    {
        $base = $this->bases[$random->getInt(0, count($this->bases) - 1)];

        return match ($random->getInt(0, 7)) {
            0 => ['truncate the archive', substr($base, 0, $random->getInt(0, strlen($base)))],
            1 => ['flip bytes of the archive', $this->flipBytes($base, $random)],
            2 => ['rename an entry', $this->renameEntry($base, $random)],
            3 => ['duplicate an entry name', $this->duplicateEntry($base, $random)],
            default => $this->mutateMember($base, $random),
        };
    }

    /**
     * Rewrites the archive with one XML member (container, package, navigation, XHTML) corrupted.
     *
     * @return array{string, string}
     */
    private function mutateMember(string $base, Randomizer $random): array
    {
        $source = $this->workDir . DIRECTORY_SEPARATOR . 'base.zip';
        $target = $this->workDir . DIRECTORY_SEPARATOR . 'mutated.zip';
        file_put_contents($source, $base);

        $reader = new ZipArchive();
        $writer = new ZipArchive();
        if ($reader->open($source) !== true || $writer->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['unreadable base', $base];
        }

        $xmlMembers = [];
        for ($i = 0; $i < $reader->numFiles; ++$i) {
            $name = (string) $reader->getNameIndex($i);
            if (preg_match('/\.(xml|opf|ncx|xhtml|html|smil)$/i', $name) === 1) {
                $xmlMembers[] = $name;
            }
        }
        $chosen = $xmlMembers === [] ? '' : $xmlMembers[$random->getInt(0, count($xmlMembers) - 1)];

        for ($i = 0; $i < $reader->numFiles; ++$i) {
            $name = (string) $reader->getNameIndex($i);
            $content = (string) $reader->getFromIndex($i);
            if ($name === $chosen) {
                $content = $this->mutate($content, $random);
            }
            $writer->addFromString($name, $content);
        }
        $reader->close();
        $writer->close();

        $bytes = (string) file_get_contents($target);
        unlink($source);
        unlink($target);

        return ['corrupt ' . $chosen, $bytes];
    }

    /**
     * Flips, truncates, inserts into, deletes from or duplicates part of $data.
     */
    private function mutate(string $data, Randomizer $random): string
    {
        switch ($random->getInt(0, 4)) {
            case 0:
                return $this->flipBytes($data, $random);
            case 1:
                return substr($data, 0, $random->getInt(0, strlen($data)));
            case 2:
                $at = $random->getInt(0, strlen($data));

                return substr($data, 0, $at) . self::SNIPPETS[$random->getInt(0, count(self::SNIPPETS) - 1)] . substr($data, $at);
            case 3:
                $from = $random->getInt(0, strlen($data));

                return substr($data, 0, $from) . substr($data, $from + $random->getInt(1, 40));
            default:
                $from = $random->getInt(0, strlen($data));
                $piece = substr($data, $from, $random->getInt(1, 200));

                return substr($data, 0, $from) . str_repeat($piece, $random->getInt(2, 20)) . substr($data, $from);
        }
    }

    private function flipBytes(string $data, Randomizer $random): string
    {
        if ($data === '') {
            return $data;
        }
        for ($count = $random->getInt(1, 8); $count > 0; --$count) {
            $data[$random->getInt(0, strlen($data) - 1)] = chr($random->getInt(0, 255));
        }

        return $data;
    }

    /**
     * Entry names are stored as plain bytes in the local and the central header, so a same-length replacement keeps
     * the archive structurally valid.
     */
    private function renameEntry(string $archive, Randomizer $random): string
    {
        $name = $this->entryName($archive, $random);
        if ($name === null) {
            return $archive;
        }
        $odd = self::ODD_NAMES[$random->getInt(0, count(self::ODD_NAMES) - 1)];
        $odd = substr(str_repeat($odd, (int) ceil(strlen($name) / strlen($odd))), 0, strlen($name));

        return str_replace($name, $odd, $archive);
    }

    private function duplicateEntry(string $archive, Randomizer $random): string
    {
        $name = $this->entryName($archive, $random);
        if ($name === null) {
            return $archive;
        }
        $other = $this->entryName($archive, $random);
        if ($other === null || strlen($other) !== strlen($name)) {
            // Same-length rename only: pick the sibling of equal length if there is one.
            return $archive;
        }

        return str_replace($other, $name, $archive);
    }

    private function entryName(string $archive, Randomizer $random): ?string
    {
        $file = $this->workDir . DIRECTORY_SEPARATOR . 'names.zip';
        file_put_contents($file, $archive);
        $zip = new ZipArchive();
        $names = [];
        if ($zip->open($file) === true) {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (strlen($name) > 3) {
                    $names[] = $name;
                }
            }
            $zip->close();
        }
        unlink($file);

        return $names === [] ? null : $names[$random->getInt(0, count($names) - 1)];
    }

    private function archive(EpubBuilder $builder): string
    {
        $file = $this->workDir . DIRECTORY_SEPARATOR . 'base_' . bin2hex(random_bytes(4)) . '.epub';
        $builder->buildEpub($file);
        $bytes = (string) file_get_contents($file);
        unlink($file);

        return $bytes;
    }

    /**
     * @return array<string, string> label => well-formed XML of the kinds the library parses
     */
    private static function xmlSamples(): array
    {
        $epub3 = EpubBuilder::epub3();
        $epub2 = EpubBuilder::epub2();

        return [
            'container.xml' => (string) $epub3->getFile('META-INF/container.xml'),
            'EPUB 3 package' => (string) $epub3->getFile('EPUB/package.opf'),
            'navigation document' => (string) $epub3->getFile('EPUB/nav.xhtml'),
            'XHTML chapter' => (string) $epub3->getFile('EPUB/text/chapter.xhtml'),
            'EPUB 2 package' => (string) $epub2->getFile('OEBPS/content.opf'),
            'NCX' => (string) $epub2->getFile('OEBPS/toc.ncx'),
        ];
    }
}
