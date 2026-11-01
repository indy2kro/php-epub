<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use PhpEpub\Exception;
use PhpEpub\Util\XmlText;
use SimpleXMLElement;

trait InteractsWithTitle
{
    /**
     * Gets the subtitle: the first dc:title with the EPUB 3 title-type "subtitle"; null when there is none.
     */
    public function getSubtitle(): ?string
    {
        $subtitle = $this->subtitleElement();

        return $subtitle instanceof SimpleXMLElement ? trim((string) $subtitle) : null;
    }

    /**
     * Sets the subtitle: a dc:title refined with title-type "subtitle", which replaces the previous subtitle;
     * null removes it. The main title is refined as "main" when no title is, so reading systems can tell
     * the two apart. Subtitles need EPUB 3 refinements: upgrade an EPUB 2 book with upgradeToEpub3() first.
     *
     * @throws Exception If the book is EPUB 2, or the subtitle is empty or not valid XML text.
     */
    public function setSubtitle(?string $subtitle): void
    {
        $this->isEpub3() || throw new Exception('Subtitles need EPUB 3 title-type refinements: call upgradeToEpub3() first');
        $existing = $this->subtitleElement();

        if ($subtitle === null) {
            if ($existing instanceof SimpleXMLElement) {
                $this->removeElement($existing);
                $this->modified = true;
            }

            return;
        }

        trim($subtitle) !== '' || throw new Exception('The subtitle cannot be empty: pass null to remove it');
        XmlText::assertValid($subtitle);

        if ($existing instanceof SimpleXMLElement) {
            $this->setText($existing, $subtitle);
            $this->removeProperties($existing, ['file-as']);
            $this->modified = true;

            return;
        }

        $titles = $this->dcElements('title');
        $main = $titles[$this->mainTitleIndex()] ?? null;
        if ($main instanceof SimpleXMLElement && $this->refinementValue($main, 'title-type') === null) {
            $this->addRefinement($main, 'title-type', 'main');
        }

        $this->addRefinement($this->addDcElement('title', $subtitle), 'title-type', 'subtitle');
        $this->modified = true;
    }

    private function subtitleElement(): ?SimpleXMLElement
    {
        foreach ($this->dcElements('title') as $element) {
            if ($this->refinementValue($element, 'title-type') === 'subtitle') {
                return $element;
            }
        }

        return null;
    }

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
