<?php

declare(strict_types=1);

namespace PhpEpub;

class ContentManager
{
    private readonly string $contentDirectory;

    /**
     * ContentManager constructor.
     *
     * @param string $contentDirectory The directory containing the EPUB content.
     */
    public function __construct(string $contentDirectory)
    {
        if (! is_dir($contentDirectory)) {
            throw new Exception("Content directory does not exist: {$contentDirectory}");
        }

        $this->contentDirectory = $contentDirectory;
    }

    /**
     * Gets a list of content files in the EPUB.
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
     * Adds a new content file to the EPUB.
     *
     * @param string $filePath The path where the content should be added.
     * @param string $content The content to add.
     *
     * @throws Exception If the file cannot be created.
     */
    public function addContent(string $filePath, string $content): void
    {
        $fullPath = $this->contentDirectory . DIRECTORY_SEPARATOR . $filePath;
        if (file_put_contents($fullPath, $content) === false) {
            throw new Exception("Failed to add content to: {$fullPath}");
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
        $fullPath = $this->contentDirectory . DIRECTORY_SEPARATOR . $filePath;
        if (! file_exists($fullPath)) {
            throw new Exception("Content file does not exist: {$fullPath}");
        }

        if (file_put_contents($fullPath, $newContent) === false) {
            throw new Exception("Failed to update content in: {$fullPath}");
        }
    }

    /**
     * Deletes a content file from the EPUB.
     *
     * @param string $filePath The path of the content to delete.
     *
     * @throws Exception If the file cannot be deleted.
     */
    public function deleteContent(string $filePath): void
    {
        $fullPath = $this->contentDirectory . DIRECTORY_SEPARATOR . $filePath;
        if (! file_exists($fullPath)) {
            throw new Exception("Content file does not exist: {$fullPath}");
        }

        if (! unlink($fullPath)) {
            throw new Exception("Failed to delete content from: {$fullPath}");
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
        $fullPath = $this->contentDirectory . DIRECTORY_SEPARATOR . $filePath;
        if (! file_exists($fullPath)) {
            throw new Exception("Content file does not exist: {$fullPath}");
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new Exception("Failed to read content from: {$fullPath}");
        }

        return $content;
    }
}
