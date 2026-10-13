<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\Limits;

/**
 * A converter that reads the book through an EpubDocumentLoader and can take over the limits the book was
 * opened with. EpubFile::convert() applies them, so a service that sets its Limits once also bounds the
 * conversion; the converter's own, tighter caps stay.
 */
interface LimitsAwareConverter extends ConverterInterface
{
    /**
     * A copy of this converter whose loader also enforces the XML and HTML caps of $limits (the smaller cap wins).
     */
    public function withLimits(Limits $limits): static;
}
