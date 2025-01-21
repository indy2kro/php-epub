<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use PhpEpub\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    private string $logFilePath;

    protected function setUp(): void
    {
        $this->logFilePath = __DIR__ . '/fixtures/output/log.txt';

        // Ensure the output directory exists
        if (!is_dir(dirname($this->logFilePath))) {
            mkdir(dirname($this->logFilePath), 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up the log file after tests
        if (file_exists($this->logFilePath)) {
            unlink($this->logFilePath);
        }
    }

    public function testLogInfoMessage(): void
    {
        $logger = new Logger($this->logFilePath);
        $logger->info('This is an info message');

        $this->assertFileExists($this->logFilePath);
        $logContents = file_get_contents($this->logFilePath);
        $this->assertNotFalse($logContents);
        $this->assertStringContainsString('INFO: This is an info message', $logContents);
    }

    public function testLogErrorMessage(): void
    {
        $logger = new Logger($this->logFilePath);
        $logger->error('This is an error message');

        $this->assertFileExists($this->logFilePath);
        $logContents = file_get_contents($this->logFilePath);
        $this->assertNotFalse($logContents);
        $this->assertStringContainsString('ERROR: This is an error message', $logContents);
    }

    public function testLogWithContext(): void
    {
        $logger = new Logger($this->logFilePath);
        $logger->info('User {username} logged in', ['username' => 'johndoe']);

        $this->assertFileExists($this->logFilePath);
        $logContents = file_get_contents($this->logFilePath);
        $this->assertNotFalse($logContents);
        $this->assertStringContainsString('INFO: User johndoe logged in', $logContents);
    }
}
