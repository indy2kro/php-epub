<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithTitle
{
    /**
     * Gets the main title of the EPUB: the dc:title refined with the EPUB 3
     * title-type "main", or else the first dc:title.
     */
    public function getTitle(): string
    {
        $titles = $this->getTitles();

        return $titles[$this->mainTitleIndex()] ?? '';
    }

    /**
     * Sets the main title of the EPUB (see getTitle()), creating dc:title when missing.
     * Other titles, such as subtitles, are kept.
     */
    public function setTitle(string $title): void
    {
        $titles = $this->getTitles();
        $titles[$this->mainTitleIndex()] = $title;

        $this->setDcValues('title', array_values($titles), null, ['file-as']);
    }

    /**
     * Gets every title (dc:title) of the EPUB, in document order.
     *
     * @return list<string>
     */
    public function getTitles(): array
    {
        return $this->getDcValues('title');
    }

    /**
     * Replaces every title of the EPUB. Existing titles are reused in order, so EPUB 3
     * title-type refinements (main, subtitle, ...) stay with their position.
     *
     * @param array<int, string> $titles At least one title.
     */
    public function setTitles(array $titles): void
    {
        $this->setDcValues('title', array_values($titles), null, ['file-as']);
    }

    /**
     * Index of the main title among getTitles(); 0 when no title is refined as "main".
     */
    private function mainTitleIndex(): int
    {
        foreach ($this->dcElements('title') as $index => $element) {
            if ($this->refinementValue($element, 'title-type') === 'main') {
                return $index;
            }
        }

        return 0;
    }
}
