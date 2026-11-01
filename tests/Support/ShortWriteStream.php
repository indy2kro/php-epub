<?php

declare(strict_types=1);

namespace PhpEpub\Test\Support;

/**
 * A stream wrapper ("shortwrite://") that, like a busy socket, accepts at most 1,000 bytes per write. What it
 * accepted is collected in $written.
 *
 * PHP's stream wrapper protocol fixes the method names (stream_open(), ...), hence the snake case.
 *
 * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
 */
final class ShortWriteStream
{
    public static string $written = '';

    /**
     * @var resource|null
     */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$written = '';

        return true;
    }

    public function stream_write(string $data): int
    {
        $accepted = substr($data, 0, 1000);
        self::$written .= $accepted;

        return strlen($accepted);
    }

    public function stream_close(): void
    {
    }
}
