<?php

declare(strict_types=1);

namespace PhpEpub\Split;

/**
 * How a SplitPlan decides where one part ends and the next begins.
 */
enum SplitKind
{
    case Toc;
    case Count;
    case Ranges;
    case Bytes;
}
