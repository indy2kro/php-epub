<?php

declare(strict_types=1);

namespace PhpEpub\Cleanup;

/**
 * What one cleanup action did (or, in a dry run, would do).
 */
final readonly class CleanupAction
{
    public const string UNREFERENCED = 'unreferenced';

    public const string STRAY_FILES = 'stray-files';

    public const string SCRIPTS = 'scripts';

    public const string REMOTE_REFERENCES = 'remote-references';

    public const string UNUSED_FONTS = 'unused-fonts';

    public const string IMAGES = 'images';

    /**
     * @param string $name One of the constants above.
     * @param list<string> $files The files removed or changed (paths relative to the book root, sorted).
     * @param int $bytesBefore The size of those files before the action.
     * @param int $bytesAfter Their size afterwards (0 for removed files).
     * @param bool $skipped True when the action could not run (e.g. image recompression without GD).
     * @param string $note Why it was skipped, or other details.
     */
    public function __construct(
        public string $name,
        public array $files = [],
        public int $bytesBefore = 0,
        public int $bytesAfter = 0,
        public bool $skipped = false,
        public string $note = ''
    ) {
    }

    public function bytesSaved(): int
    {
        return $this->bytesBefore - $this->bytesAfter;
    }
}
