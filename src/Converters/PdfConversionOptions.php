<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use PhpEpub\ConversionException;
use PhpEpub\Exception;

/**
 * What a PDF adapter (TCPDFAdapter, DompdfAdapter) includes in the PDF and how much book it accepts.
 *
 * An adapter constructed without options behaves as it always did: nothing is limited and fixed-layout books
 * are converted. Once options are passed, their defaults apply: the cover is included, there is no contents
 * page, nothing is limited and fixed-layout books are refused (see $allowFixedLayout). The page
 * size, margins and base font size are styles of the adapters; only a custom page size is an option, as
 * the styles take paper sizes by name.
 *
 * Every limit is checked while the book is read, so a hostile book is refused before it is rendered:
 * exceeding one throws a ConversionException (see its CODE_* constants). A limit equal to the book's
 * size is allowed, one byte (or chapter) more is not.
 */
final readonly class PdfConversionOptions
{
    /**
     * @param bool $includeCover Whether a cover image that no chapter shows becomes the first page.
     * @param bool $includeToc Whether a contents page, generated from the book's table of contents (titles
     *                         only, nested, linking to the chapters), follows the cover.
     * @param int|null $maxHtmlBytes The most text, in bytes, summed over the book: the chapter files as stored
     *                               (checked before a file is read) and the stylesheets, each once; null for no
     *                               limit.
     * @param int|null $maxImageBytes The most image data, in bytes, summed over the book: every file used as an
     *                                image or other resource, whatever its extension, once (fonts and stylesheets
     *                                excluded), and every inlined SVG and data: URI each time it is used; null for
     *                                no limit.
     * @param int|null $maxChapters The most spine documents rendered; null for no limit.
     * @param float|null $timeBudgetSeconds The time a conversion may take, from the start of convert(),
     *                                      checked between chapters while reading and rendering. Rendering one
     *                                      chapter (or, in Dompdf, the whole document) cannot be interrupted;
     *                                      null for no limit.
     * @param bool $allowFixedLayout Whether a fixed-layout (pre-paginated) book is converted although its
     *                               pages are then reflowed, not reproduced.
     * @param float|null $pageWidthMm A custom page width in mm; with $pageHeightMm it replaces the adapter's
     *                                paper_size. The shorter side is the width of a portrait page and the longer
     *                                the height, whichever order they are given in; the orientation style decides.
     * @param float|null $pageHeightMm A custom page height in mm.
     *
     * @throws Exception If a limit or the time budget is not greater than zero, or only one of the page
     *                   dimensions is given.
     */
    public function __construct(
        public bool $includeCover = true,
        public bool $includeToc = false,
        public ?int $maxHtmlBytes = null,
        public ?int $maxImageBytes = null,
        public ?int $maxChapters = null,
        public ?float $timeBudgetSeconds = null,
        public bool $allowFixedLayout = false,
        public ?float $pageWidthMm = null,
        public ?float $pageHeightMm = null
    ) {
        foreach (['maxHtmlBytes' => $maxHtmlBytes, 'maxImageBytes' => $maxImageBytes, 'maxChapters' => $maxChapters] as $name => $limit) {
            if ($limit !== null && $limit < 1) {
                throw new Exception("PdfConversionOptions {$name} must be greater than zero, {$limit} given");
            }
        }

        if ($timeBudgetSeconds !== null && (! is_finite($timeBudgetSeconds) || $timeBudgetSeconds <= 0)) {
            throw new Exception('PdfConversionOptions timeBudgetSeconds must be a finite number greater than zero');
        }

        if (($pageWidthMm === null) !== ($pageHeightMm === null)) {
            throw new Exception('PdfConversionOptions pageWidthMm and pageHeightMm must be given together');
        }

        foreach (['pageWidthMm' => $pageWidthMm, 'pageHeightMm' => $pageHeightMm] as $name => $size) {
            if ($size !== null && (! is_finite($size) || $size <= 0)) {
                throw new Exception("PdfConversionOptions {$name} must be a finite number greater than zero");
            }
        }
    }

    /**
     * The microtime() at which the time budget runs out when started now; null without a budget.
     */
    public function deadline(): ?float
    {
        return $this->timeBudgetSeconds === null ? null : microtime(true) + $this->timeBudgetSeconds;
    }

    /**
     * @param float|null $deadline As returned by deadline().
     *
     * @throws ConversionException If the deadline has passed.
     */
    public function assertWithinBudget(?float $deadline): void
    {
        if ($deadline !== null && microtime(true) > $deadline) {
            throw new ConversionException(
                sprintf('The conversion exceeded its time budget of %s seconds', $this->timeBudgetSeconds ?? 0),
                ConversionException::CODE_TIME_BUDGET_EXCEEDED
            );
        }
    }

    /**
     * The custom page size as the [width, height] in mm of a portrait page (shorter side first), or null
     * when the paper_size style of the adapter applies.
     *
     * @return array{float, float}|null
     */
    public function customPageSize(): ?array
    {
        return $this->pageWidthMm === null || $this->pageHeightMm === null
            ? null
            : [min($this->pageWidthMm, $this->pageHeightMm), max($this->pageWidthMm, $this->pageHeightMm)];
    }
}
