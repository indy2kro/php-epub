<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use PhpEpub\Exception;
use PhpEpub\Util\Rendition;

/**
 * EPUB Rendition metadata: whether the book is reflowable or fixed layout, and how a reading system
 * should orient, spread and flow it. EPUB 3 only; the properties are <meta property="rendition:…">
 * elements, and writing one declares the rendition prefix in the package's prefix attribute.
 * Individual spine items override them: see Spine::setItemRendition().
 */
trait InteractsWithRendition
{
    /**
     * The book's layout: "reflowable" or "pre-paginated" (fixed layout); null when not set (reflowable).
     */
    public function getRenditionLayout(): ?string
    {
        return $this->getProperty('rendition:layout');
    }

    /**
     * @param string|null $layout "reflowable" or "pre-paginated"; null removes it.
     *
     * @throws Exception If the value is not allowed or the package is not EPUB 3.
     */
    public function setRenditionLayout(?string $layout): void
    {
        $this->setRendition('layout', $layout);
    }

    /**
     * The orientation fixed-layout pages are meant for: "auto", "landscape" or "portrait"; null when not set.
     */
    public function getRenditionOrientation(): ?string
    {
        return $this->getProperty('rendition:orientation');
    }

    /**
     * @param string|null $orientation "auto", "landscape" or "portrait"; null removes it.
     *
     * @throws Exception If the value is not allowed or the package is not EPUB 3.
     */
    public function setRenditionOrientation(?string $orientation): void
    {
        $this->setRendition('orientation', $orientation);
    }

    /**
     * When the reading system may place two pages side by side: "none", "auto", "landscape", "portrait"
     * or "both"; null when not set.
     */
    public function getRenditionSpread(): ?string
    {
        return $this->getProperty('rendition:spread');
    }

    /**
     * @param string|null $spread "none", "auto", "landscape", "portrait" or "both"; null removes it.
     *
     * @throws Exception If the value is not allowed or the package is not EPUB 3.
     */
    public function setRenditionSpread(?string $spread): void
    {
        $this->setRendition('spread', $spread);
    }

    /**
     * How the content flows: "auto", "paginated", "scrolled-continuous" or "scrolled-doc"; null when not set.
     */
    public function getRenditionFlow(): ?string
    {
        return $this->getProperty('rendition:flow');
    }

    /**
     * @param string|null $flow "auto", "paginated", "scrolled-continuous" or "scrolled-doc"; null removes it.
     *
     * @throws Exception If the value is not allowed or the package is not EPUB 3.
     */
    public function setRenditionFlow(?string $flow): void
    {
        $this->setRendition('flow', $flow);
    }

    /**
     * @throws Exception
     */
    private function setRendition(string $aspect, ?string $value): void
    {
        $this->isEpub3() || throw new Exception('Rendition metadata exists only in EPUB 3 packages');

        if ($value !== null) {
            Rendition::assertValue($aspect, $value);
            Rendition::declarePrefix($this->opfXml);
        }

        $this->setProperty('rendition:' . $aspect, $value);
    }
}
