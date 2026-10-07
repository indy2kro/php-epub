<?php

declare(strict_types=1);

namespace PhpEpub\Util;

use PhpEpub\Exception;

class FileSystemHelper
{
    public function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * @param array<int, mixed> $output
     */
    /**
     * @param array<int, mixed> $output
     * @param-out list<string> $output
     */
    public function exec(string $command, array &$output, int &$returnVar): void
    {
        exec($command, $output, $returnVar);
    }

    /**
     * Runs a program without a shell and waits for it, up to $timeout seconds.
     *
     * Arguments are passed to the program as they are (nothing is interpreted by a shell),
     * and stderr is captured together with stdout. A program that runs too long is killed.
     *
     * @param non-empty-list<string> $command The program (a path, or a name looked up on PATH) and its arguments.
     * @param int|null $timeout Seconds before the program is killed; null waits indefinitely.
     *
     * @return array{exitCode: int, output: string}
     *
     * @throws Exception If the program cannot be found or started, or runs longer than $timeout.
     */
    public function runProcess(array $command, ?int $timeout): array
    {
        $program = $this->findExecutable($command[0]);
        if ($program === null) {
            throw new Exception("Program not found: {$command[0]}");
        }

        $command[0] = $program;

        // Output goes to a file, not a pipe: pipes cannot be polled on Windows.
        $outputFile = (string) tempnam(sys_get_temp_dir(), 'epub_proc_');
        $output = $outputFile === '' ? false : @fopen($outputFile, 'w');

        try {
            $process = $output === false ? false : $this->startProcess($command, $output);
            if ($output === false || $process === false) {
                throw new Exception("Failed to start {$program}: " . (error_get_last()['message'] ?? 'unknown error'));
            }

            $exitCode = $this->waitFor($process, $timeout, $program);
            fclose($output);

            // Read by path: PHP's stream does not see what the child wrote through the shared handle.
            return ['exitCode' => $exitCode, 'output' => (string) file_get_contents($outputFile)];
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if ($outputFile !== '') {
                @unlink($outputFile);
            }
        }
    }

    /**
     * Resolves a program name to an executable file: a path is checked as it is, a bare
     * name is looked up on PATH (with the PATHEXT extensions on Windows).
     */
    public function findExecutable(string $program): ?string
    {
        if (str_contains($program, '/') || str_contains($program, '\\')) {
            return is_file($program) ? $program : null;
        }

        $windows = $this->isWindows();
        $extensions = $windows ? ['', ...explode(';', strtolower((string) (getenv('PATHEXT') ?: '.exe;.bat;.cmd')))] : [''];

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }

            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $program . $extension;
                // Windows has no executable bit; the extension is what makes a file runnable.
                if (is_file($candidate) && ($windows || is_executable($candidate))) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param resource $process
     *
     * @throws Exception If the process runs longer than $timeout seconds.
     */
    private function waitFor($process, ?int $timeout, string $program): int
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        while (true) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                // The exit code is only reported by the first status call after the process ended.
                $exitCode = $status['exitcode'];
                proc_close($process);

                return $exitCode;
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                proc_close($process);

                throw new Exception("{$program} timed out after {$timeout} seconds and was stopped");
            }

            usleep(50_000);
        }
    }

    /**
     * Starts the program with stdout and stderr on one handle (and so one file offset), like 2>&1.
     *
     * @param non-empty-list<string> $command
     * @param resource $output
     *
     * @return resource|false
     */
    protected function startProcess(array $command, $output)
    {
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        return @proc_open($command, [0 => ['file', $nullDevice, 'r'], 1 => $output, 2 => $output], $pipes);
    }

    protected function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    public function fileSize(string $path): int|false
    {
        return filesize($path);
    }

    /**
     * Recursively deletes a directory and its contents.
     *
     * Deletes as much as it can and returns false, without emitting warnings, when
     * anything could not be removed (e.g. a file locked on Windows, or a read-only directory).
     */
    public function deleteDirectory(string $dir): bool
    {
        if (! is_dir($dir)) {
            return true;
        }

        $files = @scandir($dir);

        if ($files === false) {
            return false;
        }

        $deleted = true;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $dir . DIRECTORY_SEPARATOR . $file;

            // Remove links themselves; never recurse into their targets.
            if (is_link($filePath)) {
                // On Windows a directory symlink is removed with rmdir().
                $deleted = (@unlink($filePath) || @rmdir($filePath)) && $deleted;
                continue;
            }

            $deleted = (is_dir($filePath) ? $this->deleteDirectory($filePath) : @unlink($filePath)) && $deleted;
        }

        // A directory that still has contents cannot be removed; do not try (and warn).
        return $deleted && @rmdir($dir);
    }
}
