<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Exception;
use PhpEpub\InvalidEpubException;
use PhpEpub\XmlException;
use PhpEpub\ZipException;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    public function testSpecificExceptionsExtendTheBaseException(): void
    {
        $this->assertInstanceOf(Exception::class, new InvalidEpubException());
        $this->assertInstanceOf(Exception::class, new XmlException());
        $this->assertInstanceOf(InvalidEpubException::class, new XmlException());
        $this->assertInstanceOf(Exception::class, new ZipException());
    }

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
