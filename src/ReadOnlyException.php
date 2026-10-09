<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * Thrown when a change is asked of a book that is only being read (see EpubReader).
 */
class ReadOnlyException extends Exception
{
}
