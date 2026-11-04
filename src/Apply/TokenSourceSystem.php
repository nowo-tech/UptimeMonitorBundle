<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

/**
 * The few system calls behind the token intake of `nowo:uptime:apply-monitors` (since 1.18), so the refusals can be
 * tested without races or root.
 */
interface TokenSourceSystem
{
    /**
     * `lstat()` of the path (false when it cannot be read).
     *
     * @return array<int|string, int>|false
     */
    public function lstat(string $path): array|false;

    /**
     * Opens the path for reading (false on failure).
     *
     * @return false|resource
     */
    public function open(string $path): mixed;

    /**
     * `fstat()` of an open handle (false on failure).
     *
     * @param resource $handle
     *
     * @return array<int|string, int>|false
     */
    public function fstat(mixed $handle): array|false;

    /**
     * Whether the stream is an interactive terminal.
     *
     * @param resource $stream
     */
    public function isTty(mixed $stream): bool;

    /**
     * The effective uid of the process (null without ext-posix).
     */
    public function effectiveUid(): ?int;
}
