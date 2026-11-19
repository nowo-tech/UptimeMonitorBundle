<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

/**
 * One monitor in search results (since 1.32). It never carries check credentials, headers, tokens or auth settings.
 */
final readonly class MonitorSearchHit
{
    /**
     * @param int $id Monitor id
     * @param string $name Monitor name (raw: the caller escapes it for output)
     * @param string $type Monitor type value (`http`, `tcp`, `push`…)
     * @param string $tenantSlug Slug of the tenant
     * @param string $url Origin of the target (see {@see MonitorSearchUrl}); empty for groups and push monitors
     * @param string|null $status Last known status value; null when not shown or unknown
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public string $tenantSlug,
        public string $url,
        public ?string $status,
    ) {
    }
}
