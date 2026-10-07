<?php

declare(strict_types=1);

namespace PhpEpub\Test\Support;

/**
 * Makes an existing file unreadable for the current process: no read permission on POSIX,
 * an exclusive lock on Windows (where chmod only sets the read-only flag).
 */
final class UnreadableFile
{
    /**
     * @var resource|null
     */
    private $handle;

    private function __construct(private readonly string $path)
    {
    }

    public static function make(string $path): self
    {
        $file = new self($path);

        if (DIRECTORY_SEPARATOR === '\\') {
            $handle = fopen($path, 'r+');
            if ($handle !== false) {
                flock($handle, LOCK_EX);
                $file->handle = $handle;
            }
        } else {
            chmod($path, 0000);
        }

        return $file;
    }

    /**
     * False where the process can read the file anyway (e.g. running as root); tests skip then.
     */
    public function isUnreadable(): bool
    {
        return DIRECTORY_SEPARATOR === '\\' ? is_resource($this->handle) : ! is_readable($this->path);
    }

    public function restore(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }

        chmod($this->path, 0644);
    }
}
