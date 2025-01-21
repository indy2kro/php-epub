<?php

declare(strict_types=1);

namespace PhpEpub;

use Exception as BaseException;

class Exception extends BaseException
{
    /**
     * Constructs a new PhpEpub exception.
     *
     * @param string $message The exception message.
     * @param int $code The exception code.
     * @param \Throwable|null $previous The previous throwable used for the exception chaining.
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Returns a string representation of the exception.
     */
    public function __toString(): string
    {
        return sprintf(
            "%s: [Code %d] %s\n%s",
            self::class,
            $this->code,
            $this->message,
            $this->getTraceAsString()
        );
    }
}
