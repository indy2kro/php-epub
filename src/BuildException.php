<?php

declare(strict_types=1);

namespace PhpEpub;

/**
 * Thrown when a book cannot be built from the given content: invalid options, nothing to build, or a limit exceeded.
 */
class BuildException extends Exception
{
}
