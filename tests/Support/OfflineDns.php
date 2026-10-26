<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;

/**
 * SSRF guard whose DNS lookups are served from fixed maps (no network access in tests).
 */
final class OfflineDns
{
    /**
     * @param array<int|string, list<string>> $ipv4 host => A records (numeric hosts become int keys)
     * @param array<int|string, list<string>> $ipv6 host => AAAA records
     */
    public static function guard(array $ipv4 = [], array $ipv6 = []): MonitorUrlSsrfGuard
    {
        return new MonitorUrlSsrfGuard(
            static fn (string $host): array|false => $ipv4[$host] ?? false,
            static fn (string $host, int $type): array|false => isset($ipv6[$host])
                ? array_map(static fn (string $ip): array => ['type' => 'AAAA', 'ipv6' => $ip], $ipv6[$host])
                : false,
        );
    }
}
