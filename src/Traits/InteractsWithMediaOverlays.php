<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use PhpEpub\Exception;
use PhpEpub\Util\MetadataSyntax;
use PhpEpub\Util\XmlText;
use SimpleXMLElement;

/**
 * Media overlay metadata (EPUB 3 Media Overlays): the narration's total duration and the duration of
 * each SMIL overlay, the CSS classes that highlight the text being read, and the narrators.
 * The overlays themselves are attached to content documents with Manifest::setMediaOverlay().
 */
trait InteractsWithMediaOverlays
{
    /**
     * The total duration of the narration (media:duration of the book), a SMIL clock value
     * such as "0:32:29" or "1949s"; null when not set.
     */
    public function getMediaDuration(): ?string
    {
        return $this->getProperty('media:duration');
    }

    /**
     * Sets the total duration of the narration; null removes it. It should equal the sum of the overlay durations.
     *
     * @throws Exception If the value is not a SMIL clock value or the package is not EPUB 3.
     */
    public function setMediaDuration(?string $duration): void
    {
        $this->assertMediaOverlaysAllowed();
        $duration === null || $this->assertClockValue($duration);

        $this->setProperty('media:duration', $duration);
    }

    /**
     * The duration of one media overlay (a refinement of its SMIL manifest item); null when not set.
     */
    public function getMediaDurationOf(string $overlayId): ?string
    {
        $meta = $this->durationRefinements($overlayId)[0] ?? null;

        return $meta instanceof SimpleXMLElement ? trim((string) $meta) : null;
    }

    /**
     * Sets the duration of one media overlay (a SMIL manifest item); null removes it.
     *
     * @throws Exception If the item is not an application/smil+xml item of the manifest, the value is not a
     *                   SMIL clock value, or the package is not EPUB 3.
     */
    public function setMediaDurationOf(string $overlayId, ?string $duration): void
    {
        $this->assertMediaOverlaysAllowed();
        $duration === null || $this->assertClockValue($duration);
        $isOverlay = array_filter(
            $this->query($this->opfXml, '/opf:package/opf:manifest/opf:item'),
            static fn (SimpleXMLElement $item): bool => (string) $item['id'] === $overlayId && (string) $item['media-type'] === 'application/smil+xml'
        ) !== [];
        $isOverlay || throw new Exception("\"{$overlayId}\" is not a media overlay (application/smil+xml) item of the manifest");

        $metas = $this->durationRefinements($overlayId);
        if ($duration === null) {
            foreach ($metas as $meta) {
                unset($meta[0]);
            }
        } elseif ($metas === []) {
            $meta = $this->metadataNode->addChild('meta', htmlspecialchars($duration, ENT_XML1), self::OPF_NAMESPACE);
            $meta->addAttribute('refines', '#' . $overlayId);
            $meta->addAttribute('property', 'media:duration');
        } else {
            $this->setText($metas[0], $duration);
        }

        $this->modified = true;
    }

    /**
     * The duration of each media overlay, by the id of its SMIL manifest item.
     *
     * @return array<string, string>
     */
    public function getMediaDurations(): array
    {
        $durations = [];
        foreach ($this->query($this->metadataNode, './/opf:meta') as $meta) {
            $target = (string) $meta['refines'];
            if ((string) $meta['property'] === 'media:duration' && str_starts_with($target, '#')) {
                $durations[substr($target, 1)] ??= trim((string) $meta);
            }
        }

        return $durations;
    }

    /**
     * The CSS class that highlights the text being narrated (media:active-class); null when not set.
     */
    public function getMediaActiveClass(): ?string
    {
        return $this->getProperty('media:active-class');
    }

    /**
     * @param string|null $class A CSS class name without white space; null removes it.
     *
     * @throws Exception If the class name is empty or has white space, or the package is not EPUB 3.
     */
    public function setMediaActiveClass(?string $class): void
    {
        $this->setMediaClass('media:active-class', $class);
    }

    /**
     * The CSS class added to the content while the narration plays (media:playback-active-class); null when not set.
     */
    public function getMediaPlaybackActiveClass(): ?string
    {
        return $this->getProperty('media:playback-active-class');
    }

    /**
     * @param string|null $class A CSS class name without white space; null removes it.
     *
     * @throws Exception If the class name is empty or has white space, or the package is not EPUB 3.
     */
    public function setMediaPlaybackActiveClass(?string $class): void
    {
        $this->setMediaClass('media:playback-active-class', $class);
    }

    /**
     * The narrators (media:narrator), in document order.
     *
     * @return list<string>
     */
    public function getMediaNarrators(): array
    {
        return $this->getPropertyValues('media:narrator');
    }

    /**
     * @param list<string> $narrators Names; [] removes them all.
     *
     * @throws Exception If a name is empty or not valid XML text, or the package is not EPUB 3.
     */
    public function setMediaNarrators(array $narrators): void
    {
        $this->assertMediaOverlaysAllowed();
        foreach ($narrators as $narrator) {
            if (trim($narrator) === '') {
                throw new Exception('A narrator needs a name');
            }
        }

        $this->setPropertyValues('media:narrator', $narrators);
    }

    /**
     * @throws Exception
     */
    private function setMediaClass(string $property, ?string $class): void
    {
        $this->assertMediaOverlaysAllowed();
        if ($class !== null) {
            XmlText::assertValid($class);
            preg_match('/^\S+$/', $class) === 1 || throw new Exception("A CSS class name must not be empty or contain white space, got: \"{$class}\"");
        }

        $this->setProperty($property, $class);
    }

    /**
     * @throws Exception
     */
    private function assertMediaOverlaysAllowed(): void
    {
        $this->isEpub3() || throw new Exception('Media overlay metadata exists only in EPUB 3 packages');
    }

    /**
     * @throws Exception
     */
    private function assertClockValue(string $duration): void
    {
        MetadataSyntax::isSmilClockValue($duration) || throw new Exception("Not a SMIL clock value (e.g. \"0:32:29.5\", \"32:29\" or \"1949s\"): \"{$duration}\"");
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function durationRefinements(string $overlayId): array
    {
        return array_values(array_filter(
            $this->query($this->metadataNode, './/opf:meta'),
            static fn (SimpleXMLElement $meta): bool => (string) $meta['property'] === 'media:duration' && (string) $meta['refines'] === '#' . $overlayId
        ));
    }
}
