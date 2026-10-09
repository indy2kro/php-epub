<?php

declare(strict_types=1);

namespace PhpEpub\Cleanup;

/**
 * The outcome of a cleanup: one CleanupAction per enabled action, and the size of the book's files
 * (the sum of the extracted files, not the archive) before and after.
 */
final readonly class CleanupReport
{
    /**
     * @param list<CleanupAction> $actions
     * @param int $bytesBefore The size of all files of the book before the cleanup.
     * @param int $bytesAfter The size afterwards.
     * @param bool $dryRun True when nothing was changed.
     * @param int|null $archiveBytesBefore The size of the .epub file before (EpubFile::compress(), when the book has a file).
     * @param int|null $archiveBytesAfter The size of the .epub file written (null for a dry run).
     */
    public function __construct(
        public array $actions,
        public int $bytesBefore,
        public int $bytesAfter,
        public bool $dryRun = false,
        public ?int $archiveBytesBefore = null,
        public ?int $archiveBytesAfter = null
    ) {
    }

    public function getAction(string $name): ?CleanupAction
    {
        foreach ($this->actions as $action) {
            if ($action->name === $name) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Every file the cleanup removed or changed, whichever action did it.
     *
     * @return list<string>
     */
    public function getFiles(): array
    {
        $files = [];
        foreach ($this->actions as $action) {
            array_push($files, ...$action->files);
        }

        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    public function bytesSaved(): int
    {
        return $this->bytesBefore - $this->bytesAfter;
    }

    public function withArchiveSizes(?int $before, ?int $after): self
    {
        return new self($this->actions, $this->bytesBefore, $this->bytesAfter, $this->dryRun, $before, $after);
    }
}
