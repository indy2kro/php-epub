<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * Thrown when an EPUB is structurally invalid or references paths outside the book.
 */
class InvalidEpubException extends Exception
{
}
