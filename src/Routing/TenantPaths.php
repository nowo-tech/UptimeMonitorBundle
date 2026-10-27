<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Routing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function str_starts_with;
use function strlen;
use function substr;

/**
 * `dashboard.tenant_paths`: which copy of the tenant routes a tenant slug uses, and the bundle route a copied route
 * stands for (so a host's listeners can test `nowo_uptime_monitor_new` whatever the copy).
 */
final readonly class TenantPaths
{
    /**
     * @param list<array{slug_prefix: string, path: string, name_prefix: string, slug_requirement: string|null}> $tenantPaths
     */
    public function __construct(
        #[Autowire('%nowo_uptime_monitor.dashboard.tenant_paths%')]
        private array $tenantPaths = [],
    ) {
    }

    /** The route name prefix of the tenant path whose slug prefix the slug starts with (null: the default path). */
    public function namePrefixFor(string $tenantSlug): ?string
    {
        foreach ($this->tenantPaths as $tenantPath) {
            if (str_starts_with($tenantSlug, $tenantPath['slug_prefix'])) {
                return $tenantPath['name_prefix'];
            }
        }

        return null;
    }

    /** The bundle route a route name stands for: `<name_prefix>nowo_uptime_x` → `nowo_uptime_x`; others unchanged. */
    public function bundleRoute(string $route): string
    {
        foreach ($this->tenantPaths as $tenantPath) {
            if (str_starts_with($route, $tenantPath['name_prefix'] . 'nowo_uptime_')) {
                return substr($route, strlen($tenantPath['name_prefix']));
            }
        }

        return $route;
    }
}
