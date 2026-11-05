<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

use function array_key_exists;
use function array_merge;
use function array_values;
use function count;

/**
 * A validated monitors file (since 1.18): the push monitor entries per tenant slug, and the SHA-256 of the file.
 */
final readonly class MonitorFileSpec
{
    /**
     * @param array<string, list<PushMonitorSpec>> $tenants Entries per tenant slug (a tenant may list none: prune all)
     * @param string $sha256 SHA-256 of the file as read
     */
    public function __construct(
        public array $tenants,
        public string $sha256,
    ) {
    }

    /**
     * Whether the file lists this tenant.
     */
    public function hasTenant(string $tenant): bool
    {
        return array_key_exists($tenant, $this->tenants);
    }

    /**
     * The same file restricted to one listed tenant (`--tenant`).
     */
    public function onlyTenant(string $tenant): self
    {
        return new self([$tenant => $this->tenants[$tenant] ?? []], $this->sha256);
    }

    /**
     * Every entry of every tenant.
     *
     * @return list<PushMonitorSpec>
     */
    public function entries(): array
    {
        return array_merge([], ...array_values($this->tenants));
    }

    /**
     * Number of entries of every tenant.
     */
    public function count(): int
    {
        return count($this->entries());
    }
}
