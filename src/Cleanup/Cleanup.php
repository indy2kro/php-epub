<?php

declare(strict_types=1);

namespace PhpEpub\Cleanup;

use PhpEpub\EpubFile;
use PhpEpub\Exception;
use PhpEpub\FontObfuscation;
use PhpEpub\ManifestItem;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\PathResolver;
use PhpEpub\XmlParser;

/**
 * Shrinks and tidies a loaded book: removes files nothing uses, strips scripts and remote
 * references, drops unused fonts and recompresses images, according to CleanupOptions. The changes
 * are made on the loaded book (save() it afterwards; EpubFile::compress() does both and also repacks
 * at maximum deflate level). Use CleanupOptions::$dryRun to see what would change.
 *
 * Which files are "used" is decided by ReferenceGraph; the spine items, the navigation document,
 * the NCX and the cover are never removed.
 */
final readonly class Cleanup
{
    private const array FONT_TYPES = [
        'application/font-woff', 'application/font-woff2', 'application/font-sfnt', 'application/vnd.ms-opentype',
        'application/x-font-ttf', 'application/x-font-opentype', 'application/x-font-truetype', 'application/x-font-woff',
    ];

    private const array FONT_EXTENSIONS = ['ttf', 'otf', 'woff', 'woff2', 'eot'];

    /**
     * The most pixels one run decodes; the images left after that are skipped.
     */
    private const int MAX_PIXELS_PER_RUN = 120_000_000;

    private PathResolver $paths;

    private MarkupSanitizer $sanitizer;

    private XmlParser $xmlParser;

    /**
     * @param XmlParser|null $xmlParser The parser for the book's documents; by default the book's own, which keeps
     *                                  the Limits it was opened with.
     */
    public function __construct(
        private EpubFile $book,
        private ImageRecompressor $images = new ImageRecompressor(),
        ?XmlParser $xmlParser = null
    ) {
        $this->xmlParser = $xmlParser ?? $book->getXmlParser();
        $this->paths = new PathResolver();
        $this->sanitizer = new MarkupSanitizer($this->xmlParser);
    }

    /**
     * Runs the enabled actions, in this order: scripts, remote references, unused fonts, unreferenced
     * files, stray files, images.
     *
     * @throws Exception If the book is not loaded or is DRM-protected, or a file cannot be written.
     */
    public function run(CleanupOptions $options): CleanupReport
    {
        if ($options->dryRun) {
            return $this->dryRun($options);
        }

        $directory = $this->book->getTempDir() ?? throw new Exception('EPUB file must be loaded before cleaning it up.');
        $this->book->isDrmProtected() && throw new Exception('A DRM-protected book cannot be cleaned up: its content cannot be read.');

        $bytesBefore = $this->directorySize($directory);
        $actions = [];

        if ($options->stripScripts) {
            $actions[] = $this->rewriteDocuments(CleanupAction::SCRIPTS, fn (string $content, string $path): ?string => $this->sanitizer->stripScripts($content, $path), ['application/xhtml+xml', 'image/svg+xml']);
        }

        if ($options->removeRemoteReferences) {
            $actions[] = $this->rewriteDocuments(
                CleanupAction::REMOTE_REFERENCES,
                fn (string $content, string $path): ?string => $this->book->getManifest()->findByPath($path)?->mediaType === 'text/css'
                    ? $this->sanitizer->removeRemoteCss($content)
                    : $this->sanitizer->removeRemoteReferences($content, $path),
                ['application/xhtml+xml', 'image/svg+xml', 'text/css']
            );
        }

        $analysis = null;
        if ($options->removeUnusedFonts || $options->removeUnreferenced || $options->removeStrayFiles || ($options->recompressImages && $options->convertOpaquePngToJpeg)) {
            $analysis = ReferenceGraph::forBook($this->book, $this->xmlParser)->analyze();
        }

        if ($analysis instanceof ReferenceAnalysis && $options->removeUnusedFonts) {
            $actions[] = $this->removeItems(CleanupAction::UNUSED_FONTS, array_filter($analysis->unreachable, $this->isFont(...)));
        }

        if ($analysis instanceof ReferenceAnalysis && $options->removeUnreferenced) {
            $actions[] = $this->removeItems(CleanupAction::UNREFERENCED, array_filter($analysis->unreachable, fn (string $path): bool => $this->book->getManifest()->findByPath($path) instanceof ManifestItem));
        }

        if ($analysis instanceof ReferenceAnalysis && $options->removeStrayFiles) {
            $actions[] = $this->removeStrayFiles($analysis);
        }

        if ($options->recompressImages) {
            $actions[] = $this->recompressImages($options, $analysis);
        }

        $this->pruneEmptyDirectories($directory);

        return new CleanupReport($actions, $bytesBefore, $this->directorySize($directory));
    }

    /**
     * Runs the cleanup on a copy of the book (including its unsaved edits) and reports what it did.
     */
    private function dryRun(CleanupOptions $options): CleanupReport
    {
        $copy = EpubFile::openString($this->book->saveToString());

        try {
            $report = (new self($copy, $this->images, $this->xmlParser))->run($options->withDryRun(false));
        } finally {
            $copy->cleanup();
        }

        return new CleanupReport($report->actions, $report->bytesBefore, $report->bytesAfter, true);
    }

    /**
     * @param \Closure(string, string): ?string $rewrite Gives a document's new content, or null to leave it.
     * @param list<string> $mediaTypes
     */
    private function rewriteDocuments(string $name, \Closure $rewrite, array $mediaTypes): CleanupAction
    {
        $directory = $this->directory();
        $files = [];
        $before = 0;
        $after = 0;
        $unparsable = [];
        foreach ($this->book->getManifest()->getItems() as $item) {
            if ($item->path === '' || ! in_array($item->mediaType, $mediaTypes, true)) {
                continue;
            }

            $content = FileSystemHelper::readFile($this->paths->resolve($directory, $item->path));
            $new = $content === null ? null : $rewrite($content, $item->path);
            if ($content === null || $new === null) {
                if ($content !== null && $item->mediaType !== 'text/css' && ! $this->sanitizer->isWellFormed($content, $item->path)) {
                    $unparsable[] = $item->path;
                }

                continue;
            }

            // updateContent() also brings the "scripted" and "remote-resources" properties up to date.
            $this->book->getContentManager()->updateContent($item->path, $new);
            $files[] = $item->path;
            $before += strlen($content);
            $after += strlen($new);
        }

        sort($files, SORT_STRING);
        $note = $unparsable === [] ? '' : count($unparsable) . ' document(s) could not be parsed and were left unchanged: ' . implode(', ', array_slice($unparsable, 0, 10)) . (count($unparsable) > 10 ? ', ...' : '');

        return new CleanupAction($name, $files, $before, $after, false, $note);
    }

    /**
     * @param iterable<string> $paths Manifest paths.
     */
    private function removeItems(string $name, iterable $paths): CleanupAction
    {
        $directory = $this->directory();
        $manifest = $this->book->getManifest();
        $files = [];
        $before = 0;
        foreach ($paths as $path) {
            $file = $this->paths->resolve($directory, $path);
            $size = is_file($file) ? (int) filesize($file) : 0;
            if (is_file($file) && ! @unlink($file)) {
                throw new Exception("Failed to delete: {$path}");
            }

            foreach ($manifest->getItems() as $item) {
                if ($item->path === $path) {
                    $manifest->remove($item->id);
                }
            }

            $this->forgetEncryption($path);
            $files[] = $path;
            $before += $size;
        }

        sort($files, SORT_STRING);

        return new CleanupAction($name, $files, $before, 0);
    }

    private function removeStrayFiles(ReferenceAnalysis $analysis): CleanupAction
    {
        $directory = $this->directory();
        $manifest = $this->book->getManifest();
        if ($this->hasSeveralRootfiles($directory)) {
            return new CleanupAction(CleanupAction::STRAY_FILES, [], 0, 0, true, 'The book has several rootfiles (renditions); its files are kept.');
        }

        $listed = [];
        foreach ($manifest->getItems() as $item) {
            $listed[$item->path] = true;
        }

        $keep = array_flip($analysis->unmanifested);
        $files = [];
        $before = 0;
        foreach ($this->book->getContentManager()->getContentPaths() as $path) {
            if (isset($listed[$path]) || isset($keep[$path]) || $path === 'mimetype' || $path === $manifest->getOpfPath() || str_starts_with($path, 'META-INF/')) {
                continue;
            }

            $file = $this->paths->resolve($directory, $path);
            $size = (int) filesize($file);
            if (! @unlink($file)) {
                throw new Exception("Failed to delete: {$path}");
            }

            $files[] = $path;
            $before += $size;
        }

        return new CleanupAction(CleanupAction::STRAY_FILES, $files, $before, 0);
    }

    private function recompressImages(CleanupOptions $options, ?ReferenceAnalysis $analysis): CleanupAction
    {
        if (! ImageRecompressor::isAvailable()) {
            return new CleanupAction(CleanupAction::IMAGES, [], 0, 0, true, 'Neither the GD nor the Imagick extension is available.');
        }

        $directory = $this->directory();
        $manifest = $this->book->getManifest();
        $files = [];
        $before = 0;
        $after = 0;
        $skipped = [];
        $budget = self::MAX_PIXELS_PER_RUN;
        foreach ($manifest->getItems() as $item) {
            if (! in_array($item->mediaType, ['image/jpeg', 'image/png'], true) || $item->path === '') {
                continue;
            }

            $file = $this->paths->resolve($directory, $item->path);
            $data = FileSystemHelper::readFile($file);
            if ($data === null) {
                continue;
            }

            // Decoding a huge image could exhaust memory (a fatal error), so it is skipped; so are the images
            // left once the run has decoded as many pixels as it may.
            $pixels = $this->images->pixels($data);
            if ($this->images->exceedsLimits($data, $options->maxImageWidth, $options->maxImageHeight) || $pixels > $budget) {
                $skipped[] = $item->path;
                continue;
            }

            $budget -= $pixels;

            // A PNG that code or CSS escapes name, or that a document we cannot parse may use, keeps its name:
            // those references cannot be rewritten.
            $canRename = $analysis instanceof ReferenceAnalysis && $analysis->unparsable === [] && ! in_array($item->path, $analysis->mentioned, true);
            $result = $this->images->recompress(
                $data,
                $options->maxImageWidth,
                $options->maxImageHeight,
                $options->jpegQuality,
                $options->convertOpaquePngToJpeg && $canRename
            );
            if ($result === null) {
                continue;
            }

            $path = $item->path;
            if ($result['mediaType'] !== $item->mediaType) {
                $path = $this->convertedPath($item->path);
                $this->book->getContentManager()->moveContent($item->path, $path);
                $manifest->setMediaType($item->id, $result['mediaType']);
            }

            if (@file_put_contents($this->paths->resolve($directory, $path), $result['data']) === false) {
                throw new Exception("Failed to write: {$path}");
            }

            $files[] = $path;
            $before += strlen($data);
            $after += strlen($result['data']);
        }

        sort($files, SORT_STRING);
        $note = $skipped === [] ? '' : count($skipped) . ' image(s) skipped as too large to decode safely: ' . implode(', ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? ', ...' : '');

        return new CleanupAction(CleanupAction::IMAGES, $files, $before, $after, $skipped !== [] && $files === [], $note);
    }

    /**
     * The path a PNG gets when it becomes a JPEG: the same name with ".jpg", numbered when that exists.
     */
    private function convertedPath(string $path): string
    {
        // Only the file name changes: a dot in a directory name, or none in the file name, is not an extension.
        $directory = dirname($path) === '.' ? '' : dirname($path) . '/';
        $name = basename($path);
        $dot = strrpos($name, '.');
        $stem = $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
        $candidate = $directory . $stem . '.jpg';
        for ($i = 2; is_file($this->paths->resolve($this->directory(), $candidate)) || $this->book->getManifest()->findByPath($candidate) instanceof ManifestItem; $i++) {
            $candidate = "{$directory}{$stem}-{$i}.jpg";
        }

        return $candidate;
    }

    /**
     * Whether container.xml lists more than one rootfile (a book with several renditions).
     */
    private function hasSeveralRootfiles(string $directory): bool
    {
        try {
            $container = $this->xmlParser->parse($directory . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'container.xml');
        } catch (\PhpEpub\Exception) {
            // Without a readable container, nothing is known about the book's layout.
            return true;
        }

        $container->registerXPathNamespace('c', 'urn:oasis:names:tc:opendocument:xmlns:container');

        return count($container->xpath('//c:rootfile') ?: []) !== 1;
    }

    private function forgetEncryption(string $path): void
    {
        $directory = $this->directory();
        try {
            if (isset((new FontObfuscation($directory, $this->xmlParser))->obfuscatedFonts()[$path])) {
                (new FontObfuscation($directory, $this->xmlParser))->setAlgorithm($path, null);
            }
        } catch (Exception) {
            // A damaged encryption.xml is left as it is.
        }
    }

    private function pruneEmptyDirectories(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isDir() && ! $entry->isLink() && (new \FilesystemIterator($entry->getPathname()))->valid() === false) {
                @rmdir($entry->getPathname());
            }
        }
    }

    private function directorySize(string $directory): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    private function directory(): string
    {
        return $this->book->getTempDir() ?? throw new Exception('EPUB file must be loaded before cleaning it up.');
    }

    private function isFont(string $path): bool
    {
        $item = $this->book->getManifest()->findByPath($path);

        return $item instanceof ManifestItem
            && (str_starts_with($item->mediaType, 'font/') || in_array($item->mediaType, self::FONT_TYPES, true)
                || in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::FONT_EXTENSIONS, true));
    }
}
