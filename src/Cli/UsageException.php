<?php

declare(strict_types=1);

namespace PhpEpub\Cli;

use PhpEpub\Exception;

/**
 * The command line is wrong (unknown command or option, missing argument): Application reports it with exit code 2.
 */
final class UsageException extends Exception
{
}
