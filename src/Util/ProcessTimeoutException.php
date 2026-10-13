<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use PhpEpub\Exception;

/**
 * Thrown by FileSystemHelper::runProcess() when a program ran longer than its timeout and was stopped.
 */
class ProcessTimeoutException extends Exception
{
}
