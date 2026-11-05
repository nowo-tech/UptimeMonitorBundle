<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Event;

use function in_array;

/**
 * `nowo:uptime:apply-monitors` committed a tenant's monitors (since 1.18): dispatched once per tenant after the
 * commit, never on a dry run or a failure. It carries entry keys, monitor ids and the names of the changed fields
 * (`token` when a token was set or rotated), never a token or a hash: a host writes its audit from it.
 */
final readonly class MonitorsAppliedEvent
{
    /**
     * `token` is the state of the entry's push token: `set`, `rotated`, `unchanged`, `kept` or `absent`.
     *
     * @param string $tenantSlug The tenant (a project UUID in Beacon)
     * @param list<array<string, mixed>> $created
     * @param list<array<string, mixed>> $updated
     * @param list<array<string, mixed>> $unchanged
     * @param list<array<string, mixed>> $pruned
     */
    public function __construct(
        public string $tenantSlug,
        public array $created,
        public array $updated,
        public array $unchanged,
        public array $pruned,
    ) {
    }

    /**
     * Number of monitors whose token was set or rotated.
     */
    public function tokensChanged(): int
    {
        $count = 0;
        foreach ([...$this->created, ...$this->updated] as $entry) {
            $count += in_array($entry['token'] ?? null, ['set', 'rotated'], true) ? 1 : 0;
        }

        return $count;
    }
}
