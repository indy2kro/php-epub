<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * Thrown when an EPUB cannot be converted to another format.
 *
 * The code tells a refusal apart from other failures (it is 0 for those): see the CODE_* constants.
 */
class ConversionException extends Exception
{
    /**
     * The book is fixed layout (pre-paginated) and the conversion did not allow it.
     */
    public const int CODE_FIXED_LAYOUT = 1;

    /**
     * The book is bigger than a limit of PdfConversionOptions (chapters, HTML bytes or image bytes).
     */
    public const int CODE_BUDGET_EXCEEDED = 2;

    /**
     * The conversion took longer than the time budget of PdfConversionOptions.
     */
    public const int CODE_TIME_BUDGET_EXCEEDED = 3;
}
