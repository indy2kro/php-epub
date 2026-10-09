<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use DateTimeImmutable;
use DateTimeZone;
use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\ZipHandler;

/**
 * Saves a book with a chosen dcterms:modified date. EpubFile::save() stamps the current time, so with
 * an injected clock the package is patched after saving and the archive is packed again.
 *
 * @internal Used by Merge\Merger and Split\Splitter.
 */
final readonly class ModifiedDate
{
    /**
     * @param \Closure(): \DateTimeInterface|null $clock The time to record; null keeps what save() writes (now).
     *
     * @throws Exception If the book cannot be saved or the package cannot be patched.
     */
    public static function save(EpubFile $book, string $filePath, ?\Closure $clock): void
    {
        $book->save($filePath);
        if (! $clock instanceof \Closure) {
            return;
        }

        $directory = $book->getTempDir() ?? throw new Exception('EPUB file must be loaded before saving.');
        $opf = (new PathResolver())->resolve($directory, $book->getManifest()->getOpfPath());
        $package = FileSystemHelper::readFile($opf) ?? throw new Exception("Failed to read the package document: {$opf}");

        $date = DateTimeImmutable::createFromInterface($clock())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $patched = preg_replace_callback(
            '~(<meta\b(?=[^>]*\bproperty="dcterms:modified")(?![^>]*\brefines=)[^>]*>)[^<]*(</meta>)~',
            static fn (array $match): string => $match[1] . $date . $match[2],
            $package,
            1,
            $count
        );
        if ($patched === null || $count !== 1) {
            throw new Exception('Failed to set the modification date of the saved book.');
        }

        @file_put_contents($opf, $patched) !== false || throw new Exception("Failed to write the package document: {$opf}");
        (new ZipHandler())->compress($directory, $filePath);
    }
}
