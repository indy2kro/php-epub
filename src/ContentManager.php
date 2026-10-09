<?php

declare(strict_types=1);

namespace PhpEpub;

use PhpEpub\Util\ContentDocumentProperties;
use PhpEpub\Util\FileSystemHelper;
use PhpEpub\Util\HtmlText;
use PhpEpub\Util\PathResolver;
use PhpEpub\Util\ReferenceRewriter;

class ContentManager
{
    /**
     * The largest document updateManifestProperties() parses.
     */
    private const int MAX_SCANNED_BYTES = 8388608;

    private readonly string $contentDirectory;

    /**
     * ContentManager constructor.
     *
     * Paths passed to the methods below are relative to the book root (e.g. "EPUB/text/ch1.xhtml").
     * When a manifest (and spine) are given, adding and deleting files keeps them in sync.
     *
     * @param string $contentDirectory The directory containing the extracted EPUB.
     * @param \Closure(): ?string|null $fontKeyIdentifier Gives the unique identifier the book's obfuscated fonts
     *                                                    are keyed with; without it, fonts can only be handled plain.
     * @param int $maxHtmlBytes Largest XHTML or HTML document getMarkup() and getText() read (no cap by default).
     */
    public function __construct(
        string $contentDirectory,
        private readonly ?Manifest $manifest = null,
        private readonly ?Spine $spine = null,
        private readonly PathResolver $paths = new PathResolver(),
        private readonly ?\Closure $fontKeyIdentifier = null,
        private readonly int $maxHtmlBytes = PHP_INT_MAX
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
     * except container files (mimetype, META-INF/). The OPF itself is refused. Use Spine::add() to
     * also place a document in the reading order.
     *
     * An XHTML document (".xhtml", ".html" or ".htm", or a manifest item of that media type) must be
     * well-formed XML without entity declarations.
     *
     * @param string $filePath The path relative to the book root.
     * @param string $content The content to add.
     *
     * @throws Exception If the file cannot be created or is XHTML that is not well-formed.
     */
    public function addContent(string $filePath, string $content): void
    {
        $this->refusePackageDocument($filePath);
        $this->assertWellFormed($filePath, $content);
        $fullPath = $this->paths->resolve($this->contentDirectory, $filePath);
        $directory = dirname($fullPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new Exception("Failed to create directory: {$directory}");
        }

        if (@file_put_contents($fullPath, $content) === false) {
            throw new Exception("Failed to add content to: {$fullPath}");
        }

        $path = $this->paths->normalize($filePath);
        if ($this->manifest instanceof Manifest && ! $this->isContainerFile($path) && ! $this->manifest->findByPath($path) instanceof ManifestItem) {
            $this->manifest->add($path);
        }

        $this->updateContentProperties($path, $content);
    }

    /**
     * Updates an existing content file in the EPUB.
     *
     * An XHTML document must stay well-formed XML, as for addContent().
     *
     * @param string $filePath The path of the content to update.
     * @param string $newContent The new content.
     *
     * @throws Exception If the file cannot be updated or is XHTML that is not well-formed.
     */
    public function updateContent(string $filePath, string $newContent): void
    {
        $this->refusePackageDocument($filePath);
        $this->assertWellFormed($filePath, $newContent);
        $fullPath = $this->paths->resolve($this->contentDirectory, $filePath);
        // is_file(): a directory is not content (and reading one behaves differently per OS).
        if (! is_file($fullPath)) {
            throw new Exception("Content file does not exist: {$fullPath}");
        }

        if (@file_put_contents($fullPath, $newContent) === false) {
            throw new Exception("Failed to update content in: {$fullPath}");
        }

        $this->updateContentProperties($this->paths->normalize($filePath), $newContent);
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
        $this->refusePackageDocument($filePath);
        $fullPath = $this->paths->resolve($this->contentDirectory, $filePath);
        // is_file(): a directory is not content (and reading one behaves differently per OS).
        if (! is_file($fullPath)) {
            throw new Exception("Content file does not exist: {$fullPath}");
        }

        if (! @unlink($fullPath)) {
            throw new Exception("Failed to delete content from: {$fullPath}");
        }

        $item = $this->manifest?->findByPath($filePath);
        if ($item instanceof ManifestItem) {
            if ($this->spine?->contains($item->id) === true) {
                $this->spine->remove($item->id);
            }

            $this->manifest->remove($item->id);
        }

        $this->removeTableOfContentsEntries($this->paths->normalize($filePath));
    }

    /**
     * Moves or renames a file of the EPUB. Its manifest item keeps its id (so the reading order is
     * unchanged) and points at the new path, and the <guide>, the table of contents and
     * META-INF/encryption.xml (obfuscated fonts) follow it.
     *
     * References to the moved file are rewritten too: attributes such as href, src, poster, data and
     * xlink:href in the XHTML (and SVG) documents of the book, and url() and @import values in
     * stylesheets, <style> elements and style attributes. The relative references of the moved
     * document itself are rewritten when it changes directory. Query strings and fragments are kept,
     * documents that are not well-formed XML are left unchanged, and only documents that change are
     * written (they are re-serialized by DOM, so formatting details such as quote style or the
     * XML declaration can differ). The package document and the navigation links of the table of
     * contents are handled separately, as above.
     *
     * A case-only rename ("ch.xhtml" to "Ch.xhtml") works on case-insensitive filesystems too.
     *
     * @param string $from The current path relative to the book root.
     * @param string $to The new path relative to the book root; it must not exist yet.
     * @param bool $updateReferences Pass false to leave the references inside content documents as they are;
     *                               EpubFile::validate() then reports those that break.
     *
     * @throws Exception If either path is the package document or leaves the book, the file does not
     *                   exist, the target exists, or the file cannot be moved.
     */
    public function moveContent(string $from, string $to, bool $updateReferences = true): void
    {
        $this->refusePackageDocument($from);
        $this->refusePackageDocument($to);
        $source = $this->paths->resolve($this->contentDirectory, $from);
        $target = $this->paths->resolve($this->contentDirectory, $to);
        if (! is_file($source)) {
            throw new Exception("Content file does not exist: {$source}");
        }

        $fromPath = $this->paths->normalize($from);
        $toPath = $this->paths->normalize($to);

        // On a case-insensitive filesystem the target of a case-only rename "exists": it is the source itself.
        $caseOnly = file_exists($target) && $fromPath !== $toPath && strcasecmp($fromPath, $toPath) === 0
            && FileSystemHelper::isSameFile($source, $target);
        if (file_exists($target) && ! $caseOnly) {
            throw new Exception("Cannot move {$from}: {$to} already exists");
        }

        $rewrites = $updateReferences ? $this->referenceRewrites($fromPath, $toPath) : [];

        // Read the table of contents first: moving the navigation document changes how its links resolve.
        $toc = $this->manifest instanceof Manifest ? new TableOfContents($this->contentDirectory, $this->manifest) : null;
        try {
            $entries = $toc?->getEntries() ?? [];
        } catch (Exception) {
            $entries = [];
        }

        $directory = dirname($target);
        // A case-only rename goes through a temporary name, since the target is the source on such filesystems.
        $temporary = $source . '.' . bin2hex(random_bytes(4)) . '.moving';
        $moved = (is_dir($directory) || @mkdir($directory, 0777, true))
            && ($caseOnly ? @rename($source, $temporary) && @rename($temporary, $target) : @rename($source, $target));
        $moved || throw new Exception("Failed to move {$from} to {$to}");

        $item = $this->manifest?->findByPath($fromPath);
        if ($item instanceof ManifestItem) {
            $this->manifest->moveItem($item->id, $toPath);
        }

        foreach ($rewrites as $path => $content) {
            if (@file_put_contents($this->paths->resolve($this->contentDirectory, (string) $path), $content) === false) {
                throw new Exception("Failed to update the references in: {$path}");
            }
        }

        // A moved navigation document is rewritten too: its links are relative to where it is.
        $movedEntries = $this->entriesMoved($entries, $fromPath, $toPath);
        $isNav = $item instanceof ManifestItem && in_array('nav', explode(' ', $item->properties), true);
        if ($toc instanceof TableOfContents && ($movedEntries != $entries || $isNav)) {
            $toc->writeEntries($movedEntries);
        }

        $this->moveEncryptionReference($fromPath, $toPath);
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
        // is_file(): a directory is not content (and reading one behaves differently per OS).
        if (! is_file($fullPath)) {
            throw new Exception("Content file does not exist: {$fullPath}");
        }

        return FileSystemHelper::readFile($fullPath) ?? throw new Exception("Failed to read content from: {$fullPath}");
    }

    /**
     * Retrieves an XHTML or HTML document that is about to be parsed as markup, refusing one larger than the
     * size limit (see Limits::$maxHtmlBytes) before it is read.
     *
     * @param string $filePath The path of the document relative to the book root.
     *
     * @throws InvalidEpubException If the document is larger than the limit.
     * @throws Exception If the file cannot be read.
     */
    public function getMarkup(string $filePath): string
    {
        $size = @filesize($this->paths->resolve($this->contentDirectory, $filePath));
        if ($size !== false && $size > $this->maxHtmlBytes) {
            throw new InvalidEpubException("Document is larger than the limit of {$this->maxHtmlBytes} bytes: {$filePath}");
        }

        return $this->getContent($filePath);
    }

    /**
     * Adds (or overwrites) an embedded font and, with a manifest, lists it there. By default the font is
     * obfuscated with the IDPF algorithm (OCF "Font Obfuscation") and listed in META-INF/encryption.xml
     * (created when missing), which publishers do to keep the font from being reused as a file.
     * The key is the unique identifier the book's fonts are keyed with (as loaded or last saved);
     * EpubFile::save() re-keys the fonts when the identifier changes.
     *
     * Overwriting an obfuscated font with a plain one removes its encryption.xml entry.
     *
     * @param string $path The path relative to the book root, e.g. "EPUB/fonts/body.otf".
     * @param string $fontData The plain font file.
     * @param bool $obfuscate Pass false to store the font as it is.
     *
     * @throws Exception If the path is a container file, encryption.xml cannot be parsed or written, the book
     *                   has no unique identifier (or this ContentManager does not know it), or the file cannot be written.
     */
    public function addFont(string $path, string $fontData, bool $obfuscate = true): void
    {
        if ($this->isContainerFile($this->paths->normalize($path))) {
            throw new Exception("A font cannot be stored in the container directory: {$path}");
        }

        $obfuscation = new FontObfuscation($this->contentDirectory);
        $stored = $fontData;
        if ($obfuscate) {
            $stored = FontObfuscation::apply($fontData, FontObfuscation::IDPF, $this->fontKey(FontObfuscation::IDPF));
        }

        // Fails on an unreadable encryption.xml before anything is written.
        $obfuscation->obfuscatedFonts();
        $this->addContent($path, $stored);
        $obfuscation->setAlgorithm($path, $obfuscate ? FontObfuscation::IDPF : null);
    }

    /**
     * Retrieves a font file as the reading system sees it: an obfuscated font (IDPF or Adobe, listed in
     * META-INF/encryption.xml) is returned de-obfuscated, any other file as it is. A font encrypted with
     * another algorithm (DRM) is returned as it is, still encrypted.
     *
     * @param string $path The path relative to the book root.
     *
     * @throws Exception If the file cannot be read, encryption.xml cannot be parsed, or the font is obfuscated
     *                   and the book's unique identifier is unknown or gives no key (Adobe needs a urn:uuid).
     */
    public function getFontData(string $path): string
    {
        $font = $this->getContent($path);
        $algorithm = (new FontObfuscation($this->contentDirectory))->obfuscatedFonts()[$this->paths->normalize($path)] ?? null;

        return $algorithm === null ? $font : FontObfuscation::apply($font, $algorithm, $this->fontKey($algorithm));
    }

    /**
     * @throws Exception If the unique identifier is unknown or gives no key for the algorithm.
     */
    private function fontKey(string $algorithm): string
    {
        $identifier = $this->fontKeyIdentifier instanceof \Closure ? ($this->fontKeyIdentifier)() : null;
        if ($identifier === null || trim($identifier) === '') {
            throw new Exception('The unique identifier of the book is needed to obfuscate fonts; use the ContentManager of an EpubFile that has one.');
        }

        return FontObfuscation::key($algorithm, $identifier)
            ?? throw new Exception("An obfuscated font needs a urn:uuid unique identifier, not: {$identifier}");
    }

    /**
     * The plain text of an XHTML (or HTML) document: one line per paragraph, heading, list item, table row
     * and line break, with white space collapsed and entities decoded. Scripts and styles are left out. The
     * document is read in its declared encoding (UTF-8 or UTF-16) and need not be well-formed.
     *
     * @param string $filePath The path of the document relative to the book root.
     *
     * @throws InvalidEpubException If the document is larger than the size limit.
     * @throws Exception If the file cannot be read.
     */
    public function getText(string $filePath): string
    {
        return HtmlText::extract($this->getMarkup($filePath));
    }

    /**
     * Sets the EPUB 3 properties (svg, mathml, scripted, remote-resources) that every XHTML document of the
     * manifest needs because of its content, and removes those it no longer needs; other properties are kept.
     * addContent() and updateContent() do this for the one file they write; this is for books whose
     * documents were not written by this library (EpubFile::upgradeToEpub3() uses it). Nothing happens
     * for an EPUB 2 package. Documents that are missing, not well-formed or larger than 8 MiB are left alone.
     */
    public function updateManifestProperties(): void
    {
        foreach ($this->manifest?->getItems() ?? [] as $item) {
            $file = $item->path === '' ? '' : $this->paths->resolve($this->contentDirectory, $item->path);
            $isReadable = $item->mediaType === 'application/xhtml+xml' && is_file($file) && (int) @filesize($file) <= self::MAX_SCANNED_BYTES;
            $content = $isReadable ? FileSystemHelper::readFile($file) : null;
            if ($content !== null) {
                $this->updateContentProperties($item->path, $content);
            }
        }
    }

    /**
     * Sets the EPUB 3 properties an XHTML document needs because of its content (svg, mathml,
     * scripted, remote-resources) and removes those it no longer needs; other properties are kept.
     */
    private function updateContentProperties(string $path, string $content): void
    {
        $item = $this->manifest?->findByPath($path);
        // detect() is null for a document that is not well-formed, which addContent() and updateContent() refuse.
        $needed = $item instanceof ManifestItem && $item->mediaType === 'application/xhtml+xml' && $this->manifest->isEpub3()
            ? ContentDocumentProperties::detect($content)
            : null;
        if (! $item instanceof ManifestItem || $needed === null) {
            return;
        }

        foreach (ContentDocumentProperties::PROPERTIES as $property) {
            if (in_array($property, $needed, true)) {
                $this->manifest->addProperty($item->id, $property);
            } else {
                $this->manifest->removeProperty($item->id, $property);
            }
        }
    }

    /**
     * Drops the table-of-contents entries that link to a deleted file; an entry with children
     * stays as an unlinked heading (see TableOfContents::setEntries()), and the table of contents
     * may end up empty (reported by validate()). A navigation document
     * or NCX that cannot be parsed is left as is, since the file is already gone; validate()
     * reports the dangling link.
     */
    private function removeTableOfContentsEntries(string $path): void
    {
        if (! $this->manifest instanceof Manifest) {
            return;
        }

        $toc = new TableOfContents($this->contentDirectory, $this->manifest);

        try {
            $entries = $toc->getEntries();
        } catch (Exception) {
            return;
        }

        $kept = $this->entriesWithout($entries, $path);
        if ($kept != $entries) {
            // Deleting the last linked file empties the table of contents; validate() reports that.
            $toc->writeEntries($kept);
        }
    }

    /**
     * @param list<TocEntry> $entries
     *
     * @return list<TocEntry>
     */
    private function entriesMoved(array $entries, string $from, string $to): array
    {
        return array_map(
            fn (TocEntry $entry): TocEntry => new TocEntry(
                $entry->title,
                $entry->path === $from ? $to : $entry->path,
                $entry->fragment,
                $this->entriesMoved($entry->children, $from, $to)
            ),
            $entries
        );
    }

    /**
     * Points the META-INF/encryption.xml entry of a moved file (an obfuscated font) at its new path.
     * A missing or unreadable encryption.xml is left alone.
     */
    private function moveEncryptionReference(string $from, string $to): void
    {
        $file = $this->contentDirectory . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'encryption.xml';
        if (! is_file($file)) {
            return;
        }

        $xmlParser = new XmlParser();
        try {
            $encryption = $xmlParser->parse($file);
        } catch (XmlException) {
            return;
        }

        $encryption->registerXPathNamespace('enc', 'http://www.w3.org/2001/04/xmlenc#');
        $changed = false;
        foreach ($encryption->xpath('//enc:CipherReference') ?: [] as $reference) {
            if (rawurldecode((string) $reference['URI']) === $from) {
                $reference['URI'] = implode('/', array_map(rawurlencode(...), explode('/', $to)));
                $changed = true;
            }
        }

        if ($changed) {
            $xmlParser->save($encryption, $file);
        }
    }

    /**
     * @param list<TocEntry> $entries
     *
     * @return list<TocEntry>
     */
    private function entriesWithout(array $entries, string $path): array
    {
        $kept = [];
        foreach ($entries as $entry) {
            $children = $this->entriesWithout($entry->children, $path);
            if ($entry->path !== $path) {
                $kept[] = new TocEntry($entry->title, $entry->path, $entry->fragment, $children);
            } elseif ($children !== []) {
                $kept[] = new TocEntry($entry->title, '', null, $children);
            }
        }

        return $kept;
    }

    /**
     * The OPF is held in memory by Metadata, Manifest and Spine and written by EpubFile::save(),
     * so a direct write would be overwritten or would leave those objects out of date.
     *
     * @throws Exception If the path is the package document.
     */
    private function refusePackageDocument(string $filePath): void
    {
        if ($this->manifest instanceof Manifest && $this->paths->normalize($filePath) === $this->manifest->getOpfPath()) {
            throw new Exception(
                "The package document cannot be changed as content: {$filePath}. Use Metadata, Manifest and Spine instead."
            );
        }
    }

    /**
     * The documents whose references change when $from moves to $to, as [path after the move => new content].
     * XHTML and SVG documents are rewritten as XML (those that are not well-formed are skipped), stylesheets as text.
     *
     * @return array<string, string>
     */
    private function referenceRewrites(string $from, string $to): array
    {
        $rewriter = new ReferenceRewriter($this->paths);
        $rewrites = [];
        foreach ($this->getContentPaths() as $path) {
            $mediaType = $this->manifest?->findByPath($path)?->mediaType;
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $isCss = $mediaType === 'text/css' || $extension === 'css';
            $isXml = in_array($mediaType, ['application/xhtml+xml', 'image/svg+xml'], true)
                || in_array($extension, ['xhtml', 'html', 'htm', 'svg'], true);
            $content = $isCss || $isXml ? FileSystemHelper::readFile($this->paths->resolve($this->contentDirectory, $path)) : null;
            if ($content === null) {
                continue;
            }

            $newPath = $path === $from ? $to : $path;
            $rewritten = $isCss
                ? $rewriter->rewriteCss($content, $path, $newPath, $from, $to)
                : $rewriter->rewriteXml($content, $path, $newPath, $from, $to);
            if ($rewritten !== null) {
                $rewrites[$newPath] = $rewritten;
            }
        }

        return $rewrites;
    }

    /**
     * Refuses XHTML that is not well-formed XML (or declares entities): reading systems reject
     * such a document. Other files are not checked.
     *
     * @throws Exception If the path is an XHTML document and the content is not well-formed.
     */
    private function assertWellFormed(string $filePath, string $content): void
    {
        $path = $this->paths->normalize($filePath);
        $mediaType = $this->manifest?->findByPath($path)?->mediaType;
        $isXhtml = $mediaType === 'application/xhtml+xml'
            || in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['xhtml', 'html', 'htm'], true);
        if (! $isXhtml) {
            return;
        }

        try {
            (new XmlParser())->parseString($content, $path);
        } catch (XmlException $exception) {
            throw new Exception("The XHTML document is not well-formed XML: {$exception->getMessage()}", 0, $exception);
        }
    }

    /**
     * Files that belong to the container, not to the publication, and are never listed in the manifest.
     */
    private function isContainerFile(string $path): bool
    {
        return $path === 'mimetype' || str_starts_with($path, 'META-INF/');
    }
}
