<?php

declare(strict_types=1);

namespace PhpEpub;

use DateTime;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

class Logger extends AbstractLogger implements LoggerInterface
{
    public function __construct(private readonly string $logFilePath)
    {
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param string|int $level
     * @param array<string, string|Stringable> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $formattedMessage = $this->formatMessage($level, $message, $context);
        file_put_contents($this->logFilePath, $formattedMessage, FILE_APPEND);
    }

    /**
     * Formats the log message.
     *
     * @param string|int $level
     * @param array<string, string|Stringable> $context
     */
    private function formatMessage(mixed $level, string|Stringable $message, array $context, DateTime $date = new DateTime()): string
    {
        $interpolatedMessage = $this->interpolate($message, $context);
        return sprintf("[%s] %s: %s\n", $date->format('Y-m-d H:i:s'), strtoupper((string) $level), $interpolatedMessage);
    }

    /**
     * Interpolates context values into the message placeholders.
     *
     * @param array<string, string|Stringable> $context
     */
    private function interpolate(string|Stringable $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }

        return strtr((string) $message, $replace);
    }
}
