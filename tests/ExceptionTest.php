<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    public function testExceptionMessage(): void
    {
        $message = 'This is a test exception message.';
        $exception = new Exception($message);

        $this->assertSame($message, $exception->getMessage());
    }

    public function testExceptionCode(): void
    {
        $code = 404;
        $exception = new Exception('Not Found', $code);

        $this->assertEquals($code, $exception->getCode());
    }

    public function testExceptionPrevious(): void
    {
        $previous = new \Exception('Previous exception');
        $exception = new Exception('Current exception', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
