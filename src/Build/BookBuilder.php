<?php

declare(strict_types=1);

namespace PhpEpub\Build;

use DateTimeImmutable;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use PhpEpub\BookTemplate;
use PhpEpub\BuildException;
use PhpEpub\Util\CssSanitizer;
use PhpEpub\Util\HtmlSanitizer;
use PhpEpub\Util\XmlText;
use PhpEpub\XmlException;
use PhpEpub\XmlParser;

/**
 * Builds a valid EPUB 3 (with an NCX for EPUB 2 readers) from Markdown, plain text, HTML or a list of images.
 *
 * The input is untrusted. Markdown and HTML are reduced to a fixed set of text, table and image elements (see
 * Util\HtmlSanitizer), so no script, event handler, form, embedded document, style attribute or remote reference
 * reaches the book; raw HTML in Markdown is escaped, not passed on. Images are never fetched: only the bytes in
 * BookOptions::$images (matched by the path written in the content, or by file name) are used, and an image that is
 * not there is left out, leaving its alt text. Images must be JPEG, PNG, GIF or WebP, checked by content; SVG is not
 * accepted, because an SVG can carry script and remote references. Limits (see BuildLimits) bound the chapters, the
 * total size and each image.
 *
 *     $book = (new BookBuilder(new BookOptions(title: 'My Book', authors: ['Jane Doe'])))->fromMarkdown($markdown);
 *     $book->save('/path/to/book.epub');
 */
final readonly class BookBuilder
{
    private const array IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    private const array CONTAINERS = ['div', 'section', 'article', 'main', 'header', 'footer', 'aside'];

    private const int MAX_TITLE_LENGTH = 300;

    private const string TEXT_CSS = 'body{line-height:1.4}img{max-width:100%;height:auto}.cover{text-align:center}pre{white-space:pre-wrap}'
        . 'table{border-collapse:collapse}td,th{border:1px solid #888;padding:.2em .4em}';

    private const string PAGES_CSS = 'html,body{margin:0;padding:0}img{display:block;position:absolute;top:0;left:0}';

    private XmlParser $xmlParser;

    public function __construct(private BookOptions $options = new BookOptions(), ?XmlParser $xmlParser = null)
    {
        $this->xmlParser = $xmlParser ?? new XmlParser();
    }

    /**
     * Builds a book from Markdown: chapters start at headings up to BookOptions::$splitLevel. Text before the first
     * heading becomes a first chapter named after the book.
     *
     * @throws BuildException If an option is invalid, there is no content, or a limit is exceeded.
     */
    public function fromMarkdown(string $markdown): BuiltBook
    {
        $markdown = self::clean($markdown);

        return $this->buildReflowable((new MarkdownParser())->toHtml($markdown), strlen($markdown), $this->options->splitLevel);
    }

    /**
     * Builds a book from plain text: paragraphs are separated by blank lines (the lines of a paragraph are joined
     * with spaces), and a line that matches BookOptions::$chapterPattern starts a chapter and is its title.
     *
     * @throws BuildException If an option or the chapter pattern is invalid, there is no content, or a limit is exceeded.
     */
    public function fromText(string $text): BuiltBook
    {
        $text = self::clean($text);

        return $this->buildReflowable($this->textToHtml($text), strlen($text), 1);
    }

    /**
     * Builds a book from HTML: chapters start at headings up to BookOptions::$splitLevel. The HTML may be a whole
     * document or a fragment; only its sanitised body is used.
     *
     * @throws BuildException If an option is invalid, there is no content, or a limit is exceeded.
     */
    public function fromHtml(string $html): BuiltBook
    {
        $html = self::clean($html);

        return $this->buildReflowable($html, strlen($html), $this->options->splitLevel);
    }

    /**
     * Builds a fixed-layout (pre-paginated) comic or manga book with one image per page, in the order given. Each
     * page's viewport is the size of its image, the first image is the cover, and the navigation document lists
     * the pages. Set BookOptions::$direction to "rtl" for right-to-left reading.
     *
     * Entries that are not usable images (not JPEG, PNG, GIF or WebP, too large, too many pixels) are skipped with a
     * warning.
     *
     * @param list<array{name: string, bytes: string}> $images The pages: a file name (used in warnings and for the
     *                                                         sort) and the image bytes.
     * @param bool $naturalSort Order the pages by name, "page2" before "page10", instead of as given.
     *
     * @throws BuildException If an option is invalid, no image is usable, or a limit is exceeded.
     */
    public function fromImages(array $images, bool $naturalSort = false): BuiltBook
    {
        $this->validateOptions();
        $limits = $this->options->limits;

        if (count($images) > $limits->maxImages) {
            throw new BuildException("Too many images: the limit is {$limits->maxImages}");
        }

        $pages = [];
        $total = 0;
        foreach ($images as $index => $image) {
            /** @phpstan-ignore-next-line */
            if (! is_array($image) || ! isset($image['name'], $image['bytes']) || ! is_string($image['name']) || ! is_string($image['bytes'])) {
                throw new BuildException('Image ' . ($index + 1) . ' needs a name and bytes');
            }

            $total += strlen($image['bytes']);
            $pages[] = ['name' => $image['name'], 'bytes' => $image['bytes']];
        }

        $this->assertTotal($total);
        $naturalSort && usort($pages, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        $warnings = [];
        $usable = [];
        foreach ($pages as $page) {
            $info = $this->inspectImage($page['bytes']);
            if (is_string($info)) {
                $warnings[] = 'Skipped ' . self::printable($page['name']) . ': ' . $info;
            } else {
                $usable[] = $info;
            }
        }

        if ($usable === []) {
            throw new BuildException('There is no usable image: pages must be JPEG, PNG, GIF or WebP images');
        }

        if (count($usable) > $limits->maxChapters) {
            throw new BuildException("Too many pages: the limit is {$limits->maxChapters}");
        }

        $package = $this->newPackage('images');
        $package->addResource('style', 'css/style.css', self::PAGES_CSS, 'text/css');
        foreach ($usable as $index => $image) {
            $number = $index + 1;
            $name = sprintf('page-%03d', $number);
            $href = "images/{$name}.{$image['extension']}";
            $package->addResource("img-{$name}", $href, $image['bytes'], $image['mime'], $number === 1 ? 'cover-image' : '');
            $package->addDocument(
                $name,
                "text/{$name}.xhtml",
                BookPackage::document(
                    "Page {$number}",
                    $this->options->language,
                    $this->options->direction,
                    "<meta name=\"viewport\" content=\"width={$image['width']}, height={$image['height']}\"/><link rel=\"stylesheet\" type=\"text/css\" href=\"../css/style.css\"/>",
                    "<img src=\"../{$href}\" alt=\"Page {$number}\" width=\"{$image['width']}\" height=\"{$image['height']}\"/>"
                )
            );
            $package->addTocEntry("Page {$number}", "text/{$name}.xhtml");
        }

        $package->setCover('img-page-001');
        $package->addLandmark('cover', 'Cover', 'text/page-001.xhtml');

        return new BuiltBook($package->toEpub(), count($usable), $warnings);
    }

    private function buildReflowable(string $html, int $inputBytes, int $splitLevel): BuiltBook
    {
        $this->validateOptions();
        $options = $this->options;
        $limits = $options->limits;

        if (count($options->images) > $limits->maxImages) {
            throw new BuildException("Too many images: the limit is {$limits->maxImages}");
        }

        $total = $inputBytes + strlen($options->css) + strlen($options->coverImage ?? '');
        foreach ($options->images as $bytes) {
            $total += strlen($bytes);
        }

        $this->assertTotal($total);

        $warnings = [];
        $images = $this->supplied($warnings);
        $cover = null;
        if ($options->coverImage !== null) {
            $cover = $this->inspectImage($options->coverImage);
            if (is_string($cover)) {
                throw new BuildException('The cover image is not usable: ' . $cover);
            }
        }

        /** @var array<string, string> $used Normalised image path => file name in the book. */
        $used = [];
        $resolve = function (string $source) use ($images, &$used, &$warnings): ?string {
            $key = self::lookup($source, $images);
            if ($key === null) {
                $warnings[] = 'Image not supplied: ' . self::printable($source);

                return null;
            }

            $used[$key] ??= sprintf('img-%03d.%s', count($used) + 1, $images[$key]['extension']);

            return '../images/' . $used[$key];
        };

        $body = HtmlSanitizer::parseBody($html);
        (new HtmlSanitizer($resolve, null, false))->sanitize($body);

        $chapters = $this->split($body, $splitLevel);
        if ($chapters === []) {
            throw new BuildException('There is no content to build a book from');
        }

        if (count($chapters) > $limits->maxChapters) {
            throw new BuildException("Too many chapters: the limit is {$limits->maxChapters}");
        }

        $documents = $this->documents($chapters);

        $package = $this->newPackage('text');
        $package->addResource('style', 'css/style.css', self::TEXT_CSS . "\n" . CssSanitizer::sanitize($options->css, static fn (): ?string => null), 'text/css');
        $head = '<link rel="stylesheet" type="text/css" href="../css/style.css"/>';

        if (is_array($cover)) {
            $package->addResource('cover-img', 'images/cover.' . $cover['extension'], $cover['bytes'], $cover['mime'], 'cover-image');
            $package->setCover('cover-img');
            $package->addDocument(
                'cover',
                'text/cover.xhtml',
                BookPackage::document($options->title, $options->language, $options->direction, $head, '<div class="cover"><img src="../images/cover.' . $cover['extension'] . '" alt="Cover"/></div>')
            );
            $package->addLandmark('cover', 'Cover', 'text/cover.xhtml');
        }

        foreach ($used as $key => $name) {
            $package->addResource(pathinfo($name, PATHINFO_FILENAME), 'images/' . $name, $images[$key]['bytes'], $images[$key]['mime']);
        }

        foreach ($chapters as $index => $chapter) {
            $name = sprintf('chapter-%03d', $index + 1);
            $xhtml = BookPackage::document($chapter['title'], $options->language, $options->direction, $head, $documents[$index]);
            $this->assertWellFormed($xhtml, $name);
            $package->addDocument($name, "text/{$name}.xhtml", $xhtml);
            $package->addTocEntry($chapter['title'], "text/{$name}.xhtml");
        }

        $package->addLandmark('bodymatter', $chapters[0]['title'], 'text/chapter-001.xhtml');

        return new BuiltBook($package->toEpub(), count($chapters), array_values(array_unique($warnings)));
    }

    /**
     * @param 'text'|'images' $kind
     */
    private function newPackage(string $kind): BookPackage
    {
        $options = $this->options;

        return new BookPackage(
            trim($options->title),
            trim($options->language),
            $options->identifier === null ? BookTemplate::uuidUrn() : trim($options->identifier),
            array_map(trim(...), $options->authors),
            trim($options->description),
            trim($options->publisher),
            $this->normalizedDate(),
            $options->direction,
            $kind
        );
    }

    /**
     * @throws BuildException If an option is invalid.
     */
    private function validateOptions(): void
    {
        $options = $this->options;

        try {
            XmlText::assertValid($options->title, $options->language, $options->description, $options->publisher, ...$options->authors);
            XmlText::assertValid($options->identifier ?? '');
        } catch (\PhpEpub\Exception $exception) {
            throw new BuildException('Invalid book metadata: ' . $exception->getMessage(), 0, $exception);
        }

        if (trim($options->title) === '') {
            throw new BuildException('A book needs a title');
        }

        if (preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', trim($options->language)) !== 1) {
            throw new BuildException("Invalid language tag: {$options->language}");
        }

        if ($options->identifier !== null && trim($options->identifier) === '') {
            throw new BuildException('The identifier is empty');
        }

        foreach ($options->authors as $author) {
            if (trim($author) === '') {
                throw new BuildException('An author name is empty');
            }
        }

        if ($options->splitLevel < 1 || $options->splitLevel > 6) {
            throw new BuildException('The split level must be between 1 and 6');
        }

        if (! in_array($options->direction, ['ltr', 'rtl'], true)) {
            throw new BuildException('The direction must be "ltr" or "rtl"');
        }

        $this->normalizedDate();
    }

    /**
     * @throws BuildException If the date cannot be read.
     */
    private function normalizedDate(): string
    {
        $date = $this->options->date;
        if ($date === null) {
            return '';
        }

        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        $date = trim($date);
        if (preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/', $date, $match) === 1) {
            if (isset($match[3]) ? checkdate((int) $match[2], (int) $match[3], (int) $match[1]) : (! isset($match[2]) || ((int) $match[2] >= 1 && (int) $match[2] <= 12))) {
                return $date;
            }

            throw new BuildException("Invalid date: {$date}");
        }

        try {
            return (new DateTimeImmutable($date))->format('Y-m-d');
        } catch (\Exception $exception) {
            throw new BuildException("Invalid date: {$date}", 0, $exception);
        }
    }

    private function assertTotal(int $bytes): void
    {
        if ($bytes > $this->options->limits->maxTotalBytes) {
            throw new BuildException("The content is too large: the limit is {$this->options->limits->maxTotalBytes} bytes");
        }
    }

    /**
     * Valid UTF-8 without characters XML cannot hold, and without a byte order mark.
     */
    private static function clean(string $content): string
    {
        // Invalid bytes become U+FFFD (the same trick as htmlspecialchars' ENT_SUBSTITUTE, undone for the markup).
        $content = htmlspecialchars_decode(htmlspecialchars($content, ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES);
        $content = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $content);

        return str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;
    }

    /**
     * Fixed text for a warning: no markup, control characters or long values.
     */
    private static function printable(string $value): string
    {
        $value = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);

        return strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value;
    }

    /**
     * @throws BuildException If the chapter pattern is invalid.
     */
    private function textToHtml(string $text): string
    {
        $pattern = $this->options->chapterPattern;
        if (@preg_match($pattern, '') === false) {
            throw new BuildException("Invalid chapter pattern: {$pattern}");
        }

        $html = '';
        $paragraph = [];
        // The last, empty line ends the last paragraph.
        foreach ([...explode("\n", str_replace(["\r\n", "\r"], "\n", $text)), ''] as $line) {
            $line = trim($line);
            $isChapter = $line !== '' ? @preg_match($pattern, $line) : 0;
            if ($isChapter === false) {
                throw new BuildException('The chapter pattern failed: ' . preg_last_error_msg());
            }

            if (($line === '' || $isChapter === 1) && $paragraph !== []) {
                $html .= '<p>' . htmlspecialchars(implode(' ', $paragraph), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>\n";
                $paragraph = [];
            }

            if ($isChapter === 1) {
                $html .= '<h1>' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h1>\n";
            } elseif ($line !== '') {
                $paragraph[] = $line;
            }
        }

        return $html;
    }

    /**
     * The images in BookOptions::$images that are usable, by normalised path; the others are skipped with a warning.
     *
     * @param list<string> $warnings
     *
     * @return array<string, array{mime: string, extension: string, bytes: string, width: int, height: int}>
     */
    private function supplied(array &$warnings): array
    {
        $images = [];
        foreach ($this->options->images as $path => $bytes) {
            $info = $this->inspectImage($bytes);
            if (is_string($info)) {
                $warnings[] = 'Skipped image ' . self::printable((string) $path) . ': ' . $info;
            } else {
                $images[self::normalizePath((string) $path)] = $info;
            }
        }

        return $images;
    }

    /**
     * @return array{mime: string, extension: string, bytes: string, width: int, height: int}|string The image, or why it is not usable.
     */
    private function inspectImage(string $bytes): array|string
    {
        $limits = $this->options->limits;
        if ($bytes === '') {
            return 'it is empty';
        }

        if (strlen($bytes) > $limits->maxImageBytes) {
            return "it is larger than {$limits->maxImageBytes} bytes";
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! isset(self::IMAGE_TYPES[$info['mime']])) {
            return 'it is not a JPEG, PNG, GIF or WebP image';
        }

        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > $limits->maxPixels) {
            return "its size ({$info[0]}x{$info[1]}) is outside the limit of {$limits->maxPixels} pixels";
        }

        return ['mime' => $info['mime'], 'extension' => self::IMAGE_TYPES[$info['mime']], 'bytes' => $bytes, 'width' => $info[0], 'height' => $info[1]];
    }

    /**
     * The key of the supplied image a reference in the content names: the same normalised path, else the only
     * supplied image with that file name. Remote and data: references name none.
     *
     * @param array<string, mixed> $images
     */
    private static function lookup(string $source, array $images): ?string
    {
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', trim($source)) === 1) {
            return null;
        }

        $path = self::normalizePath($source);
        if ($path === '') {
            return null;
        }

        if (isset($images[$path])) {
            return $path;
        }

        $matches = array_filter(array_keys($images), static fn (string $key): bool => basename($key) === basename($path));

        return count($matches) === 1 ? (string) reset($matches) : null;
    }

    /**
     * "a/b.png" for "./a/x/../b.png?v=1#top": decoded, without query and fragment, "." and ".." resolved, never above the root.
     */
    private static function normalizePath(string $reference): string
    {
        $reference = rawurldecode(explode('#', explode('?', trim($reference), 2)[0], 2)[0]);
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $reference)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * The chapters of a sanitised body: they start at headings up to $level; content before the first heading is a
     * chapter named after the book.
     *
     * @return list<array{title: string, nodes: list<DOMNode>}>
     */
    private function split(DOMElement $body, int $level): array
    {
        $chapters = [];
        $current = null;
        foreach ($this->units($body, $level) as $node) {
            $heading = self::headingLevel($node);
            if ($heading !== null && $heading <= $level) {
                if ($current !== null && self::hasContent($current['nodes'])) {
                    $chapters[] = $current;
                }

                $current = ['title' => self::title($node->textContent), 'nodes' => [$node]];
            } else {
                $current ??= ['title' => '', 'nodes' => []];
                $current['nodes'][] = $node;
            }
        }

        if ($current !== null && self::hasContent($current['nodes'])) {
            $chapters[] = $current;
        }

        foreach ($chapters as $index => $chapter) {
            if ($chapter['title'] === '') {
                $chapters[$index]['title'] = $index === 0 ? self::title($this->options->title) : 'Section ' . ($index + 1);
            }
        }

        return $chapters;
    }

    /**
     * The nodes to distribute over chapters: wrappers that hold a chapter heading are opened.
     *
     * @return list<DOMNode>
     */
    private function units(DOMNode $parent, int $level): array
    {
        $units = [];
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->localName ?? ''), self::CONTAINERS, true) && self::containsHeading($child, $level)) {
                array_push($units, ...$this->units($child, $level));
            } else {
                $units[] = $child;
            }
        }

        return $units;
    }

    private static function containsHeading(DOMElement $element, int $level): bool
    {
        for ($i = 1; $i <= $level; $i++) {
            if ($element->getElementsByTagName("h{$i}")->length > 0) {
                return true;
            }
        }

        return false;
    }

    private static function headingLevel(DOMNode $node): ?int
    {
        return $node instanceof DOMElement && preg_match('/^h([1-6])$/i', $node->localName ?? '', $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * @param list<DOMNode> $nodes
     */
    private static function hasContent(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement || ($node instanceof DOMText && trim($node->data, " \t\n\r\0\x0B\u{00A0}") !== '')) {
                return true;
            }
        }

        return false;
    }

    private static function title(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return preg_match('/^.{0,' . self::MAX_TITLE_LENGTH . '}/su', $text, $match) === 1 ? $match[0] : $text;
    }

    /**
     * The XHTML body of every chapter, with ids made unique and valid and links between chapters pointing at the
     * chapter files; a link whose target is not in the book loses its href.
     *
     * @param list<array{title: string, nodes: list<DOMNode>}> $chapters
     *
     * @return list<string>
     */
    private function documents(array $chapters): array
    {
        $containers = [];
        foreach ($chapters as $chapter) {
            $first = $chapter['nodes'][0] ?? null;
            $document = $first?->ownerDocument;
            if (! $document instanceof DOMDocument) {
                $document = new DOMDocument();
            }

            $container = $document->createElement('div');
            foreach ($chapter['nodes'] as $node) {
                $container->appendChild($node);
            }

            $containers[] = $container;
        }

        /** @var array<string, int> $ids Id => index of the chapter that has it. */
        $ids = [];
        foreach ($containers as $index => $container) {
            foreach ($container->getElementsByTagName('*') as $element) {
                $id = $element->getAttribute('id');
                if ($id === '') {
                    continue;
                }

                if (isset($ids[$id]) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $id) !== 1) {
                    $element->removeAttribute('id');
                } else {
                    $ids[$id] = $index;
                }
            }
        }

        $documents = [];
        foreach ($containers as $index => $container) {
            foreach ($container->getElementsByTagName('a') as $link) {
                $href = $link->getAttribute('href');
                if (! str_starts_with($href, '#')) {
                    continue;
                }

                $id = rawurldecode(substr($href, 1));
                if (! isset($ids[$id])) {
                    $link->removeAttribute('href');
                    $link->removeAttribute('rel');
                } elseif ($ids[$id] !== $index) {
                    $link->setAttribute('href', sprintf('chapter-%03d.xhtml#%s', $ids[$id] + 1, $id));
                } else {
                    $link->setAttribute('href', '#' . $id);
                }
            }

            $xhtml = '';
            foreach ($container->childNodes as $child) {
                $xhtml .= (string) $container->ownerDocument?->saveXML($child);
            }

            $documents[] = $xhtml;
        }

        return $documents;
    }

    /**
     * @throws BuildException If the chapter is not well-formed XML (it always is; this guards the invariant).
     */
    private function assertWellFormed(string $xhtml, string $name): void
    {
        try {
            $this->xmlParser->parseString($xhtml, $name);
        } catch (XmlException $exception) {
            throw new BuildException("Failed to produce valid XHTML for {$name}: " . $exception->getMessage(), 0, $exception);
        }
    }
}
