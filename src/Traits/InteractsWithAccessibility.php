<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

/**
 * Accessibility metadata (EPUB Accessibility 1.1): schema.org access modes, features, hazards and
 * summary, and the conformance statement. EPUB 3 packages store them as <meta property="…"> elements;
 * EPUB 2 packages as <meta name="…" content="…"/>, which the specification allows there.
 */
trait InteractsWithAccessibility
{
    /**
     * Gets the access modes (schema:accessMode): the senses needed to consume the content, e.g.
     * "textual", "visual", "auditory", "tactile".
     *
     * @return list<string>
     */
    public function getAccessModes(): array
    {
        return $this->accessibilityValues('schema:accessMode');
    }

    /**
     * @param list<string> $modes
     */
    public function setAccessModes(array $modes): void
    {
        $this->setAccessibilityValues('schema:accessMode', $modes);
    }

    /**
     * Gets the sufficient access modes (schema:accessModeSufficient): each value is a comma-separated
     * set of access modes that suffices to read the book, e.g. "textual,visual" or "textual".
     *
     * @return list<string>
     */
    public function getAccessModesSufficient(): array
    {
        return $this->accessibilityValues('schema:accessModeSufficient');
    }

    /**
     * @param list<string> $sets Comma-separated access mode sets, e.g. ["textual,visual", "textual"].
     */
    public function setAccessModesSufficient(array $sets): void
    {
        $this->setAccessibilityValues('schema:accessModeSufficient', $sets);
    }

    /**
     * Gets the accessibility features (schema:accessibilityFeature), e.g. "alternativeText",
     * "tableOfContents", "structuralNavigation", "displayTransformability", or "none".
     *
     * @return list<string>
     */
    public function getAccessibilityFeatures(): array
    {
        return $this->accessibilityValues('schema:accessibilityFeature');
    }

    /**
     * @param list<string> $features
     */
    public function setAccessibilityFeatures(array $features): void
    {
        $this->setAccessibilityValues('schema:accessibilityFeature', $features);
    }

    /**
     * Gets the accessibility hazards (schema:accessibilityHazard), e.g. "noFlashingHazard",
     * "noMotionSimulationHazard", "noSoundHazard", or "none" / "unknown".
     *
     * @return list<string>
     */
    public function getAccessibilityHazards(): array
    {
        return $this->accessibilityValues('schema:accessibilityHazard');
    }

    /**
     * @param list<string> $hazards
     */
    public function setAccessibilityHazards(array $hazards): void
    {
        $this->setAccessibilityValues('schema:accessibilityHazard', $hazards);
    }

    /**
     * Gets the human-readable accessibility summary (schema:accessibilitySummary); null when there is none.
     */
    public function getAccessibilitySummary(): ?string
    {
        return $this->accessibilityValues('schema:accessibilitySummary')[0] ?? null;
    }

    /**
     * Sets the accessibility summary; null or "" removes it.
     */
    public function setAccessibilitySummary(?string $summary): void
    {
        $this->setAccessibilityValues('schema:accessibilitySummary', $summary === null || $summary === '' ? [] : [$summary]);
    }

    /**
     * Gets the conformance statement (dcterms:conformsTo), e.g. "EPUB Accessibility 1.1 - WCAG 2.1 Level AA";
     * null when there is none.
     */
    public function getConformsTo(): ?string
    {
        return $this->accessibilityValues('dcterms:conformsTo')[0] ?? null;
    }

    /**
     * Sets the conformance statement; null or "" removes it.
     */
    public function setConformsTo(?string $conformance): void
    {
        $this->setAccessibilityValues('dcterms:conformsTo', $conformance === null || $conformance === '' ? [] : [$conformance]);
    }

    /**
     * Gets who certified the conformance (a11y:certifiedBy); null when there is none.
     */
    public function getCertifiedBy(): ?string
    {
        return $this->accessibilityValues('a11y:certifiedBy')[0] ?? null;
    }

    /**
     * Sets who certified the conformance; null or "" removes it.
     */
    public function setCertifiedBy(?string $certifier): void
    {
        $this->setAccessibilityValues('a11y:certifiedBy', $certifier === null || $certifier === '' ? [] : [$certifier]);
    }

    /**
     * @return list<string>
     */
    private function accessibilityValues(string $term): array
    {
        return $this->isEpub3() ? $this->getPropertyValues($term) : $this->getMetaValues($term);
    }

    /**
     * @param list<string> $values
     */
    private function setAccessibilityValues(string $term, array $values): void
    {
        if ($this->isEpub3()) {
            $this->setPropertyValues($term, $values);
        } else {
            $this->setMetaValues($term, $values);
        }
    }
}
