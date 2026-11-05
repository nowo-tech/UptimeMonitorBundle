<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

/**
 * One refusal of `nowo:uptime:apply-monitors` (since 1.18): where (a JSON pointer into the monitors file, or
 * `token-file:/<key>`), a stable code, and the exit code class it belongs to. Never a value from the input.
 */
final readonly class ApplyError
{
    public const EXIT_USAGE    = 2;
    public const EXIT_FILE     = 3;
    public const EXIT_TOKEN    = 4;
    public const EXIT_TENANT   = 5;
    public const EXIT_CONFLICT = 6;

    /**
     * @param string $path JSON pointer (`/tenants/<uuid>/monitors/0/url_key`) or `token-file:/<key>`
     * @param string $code Stable error code (`url_key_taken`, `token_low_entropy`, …)
     * @param int $exitCode One of the `EXIT_*` classes
     */
    public function __construct(
        public string $path,
        public string $code,
        public int $exitCode,
    ) {
    }

    /**
     * The output form: `{path, code}`.
     *
     * @return array{path: string, code: string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'code' => $this->code];
    }
}
