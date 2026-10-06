<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\PathResolver;

class ContentManager
{
    private readonly string $contentDirectory;

    /**
     * ContentManager constructor.
     *
     * Paths passed to the methods below are relative to the book root (e.g. "EPUB/text/ch1.xhtml").
     * When a manifest (and spine) are given, adding and deleting files keeps them in sync.
     *
     * @param string $contentDirectory The directory containing the extracted EPUB.
     */
    public function __construct(
        string $contentDirectory,
        private readonly ?Manifest $manifest = null,
        private readonly ?Spine $spine = null,
        private readonly PathResolver $paths = new PathResolver()
    ) {
        if (! is_dir($contentDirectory)) {
            throw new Exception("Content directory does not exist: {$contentDirectory}");
        }

        $this->contentDirectory = $contentDirectory;
    }

    /**
     * Gets the files in the EPUB as paths relative to the book root, sorted, using "/".
     *
     * These paths can be passed straight back to getContent(), updateContent() and deleteContent().
     *
     * @return list<string>
     */
    public function getContentPaths(): array
    {
        $root = (string) realpath($this->contentDirectory);
        $paths = array_map(
            static fn (string $file): string => str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1)),
            $this->getContentList()
        );
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Gets a list of content files in the EPUB as absolute paths inside the temp directory.
     *
     * @deprecated Use getContentPaths(), whose paths work with the other ContentManager methods.
     *
     * @return array<string> List of content file paths.
     */
    public function getContentList(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->contentDirectory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

    /**
     * Adds (or overwrites) a content file, creating missing directories.
     *
     * New files are added to the manifest (with a media type guessed from the extension),
     * except container files (mimetype, META-INF/, the OPF itself). Use Spine::add() to
     * also place a document in the reading order.
     *
     * @param string $filePath The path relative to the book root.
     * @param string $content The content to add.
     *
     * @throws Exception If the file cannot be created.
     */
    public function addContent(string $filePath, string $content): void
    {
        $fullPath = $this->paths->resolve($this->contentDirectory, $filePath);
        $directory = dirname($fullPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new Exception("Failed to create directory: {$directory}");
        }

        if (file_put_contents($fullPath, $content) === false) {
            throw new Exception("Failed to add content to: {$fullPath}");
        }

        $path = $this->paths->normalize($filePath);
        if ($this->manifest instanceof Manifest && ! $this->isContainerFile($path) && ! $this->manifest->findByPath($path) instanceof ManifestItem) {
            $this->manifest->add($path);
        }
    }

    /**
     * Updates an existing content file in the EPUB.
     *
     * @param string $filePath The path of the content to update.
     * @param string $newContent The new content.
     *
     * @throws Exception If the file cannot be updated.
     */
    public function updateContent(string $filePath, string $newContent): void
    {
        $fullPath = $this->paths->resolve($this->contentDirectory, $filePath);
        if (! file_exists($fullPath)) {
            throw new Exception("Content file does not exist: {$fullPath}");
        }

        if (file_put_contents($fullPath, $newContent) === false) {
            throw new Exception("Failed to update content in: {$fullPath}");
        }
    }

    /**
     * Deletes a content file from the EPUB, with its manifest item and spine entry.
     *
     * @param string $filePath The path relative to the book root.
     *
     * @throws Exception If the file cannot be deleted.
     */
    public function deleteContent(string $filePath): void
    {
        $fullPath = $this->paths->resolve($this->contentDirectory, $filePath);
        if (! file_exists($fullPath)) {
            throw new Exception("Content file does not exist: {$fullPath}");
        }

        if (! unlink($fullPath)) {
            throw new Exception("Failed to delete content from: {$fullPath}");
        }

        $item = $this->manifest?->findByPath($filePath);
        if ($item instanceof ManifestItem) {
            if ($this->spine?->contains($item->id) === true) {
                $this->spine->remove($item->id);
            }

            $this->manifest->remove($item->id);
        }
    }

    /**
     * Retrieves the content of a file in the EPUB.
     *
     * @param string $filePath The path of the content to retrieve.
     *
     * @return string The content of the file.
     *
     * @throws Exception If the file cannot be read.
     */
    public function getContent(string $filePath): string
    {
        $fullPath = $this->paths->resolve($this->contentDirectory, $filePath);
        if (! file_exists($fullPath)) {
            throw new Exception("Content file does not exist: {$fullPath}");
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new Exception("Failed to read content from: {$fullPath}");
        }

        return $content;
    }

    /**
     * Files that belong to the container, not to the publication, and are never listed in the manifest.
     */
    private function isContainerFile(string $path): bool
    {
        return $path === 'mimetype'
            || str_starts_with($path, 'META-INF/')
            || ($this->manifest instanceof Manifest && $path === $this->manifest->getOpfPath());
    }
}
