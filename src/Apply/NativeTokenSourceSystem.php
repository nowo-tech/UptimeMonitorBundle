<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

use function fopen;
use function fstat;
use function function_exists;
use function lstat;
use function posix_geteuid;
use function stream_isatty;

/**
 * {@see TokenSourceSystem} on the real filesystem and process (since 1.18).
 */
final readonly class NativeTokenSourceSystem implements TokenSourceSystem
{
    /**
     * `lstat()` of the path, warnings silenced (false when it cannot be read).
     */
    public function lstat(string $path): array|false
    {
        return @lstat($path);
    }

    /**
     * Opens the path read-only, warnings silenced (false on failure).
     */
    public function open(string $path): mixed
    {
        return @fopen($path, 'r');
    }

    /**
     * `fstat()` of the open handle.
     */
    public function fstat(mixed $handle): array|false
    {
        return fstat($handle);
    }

    /**
     * Whether the stream is an interactive terminal.
     */
    public function isTty(mixed $stream): bool
    {
        return stream_isatty($stream);
    }

    /**
     * The effective uid (null without ext-posix).
     */
    public function effectiveUid(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }
}
