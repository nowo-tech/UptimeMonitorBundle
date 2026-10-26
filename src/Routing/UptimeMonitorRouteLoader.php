<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouteCollection;

use function ltrim;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function substr_replace;

/**
 * Route import `type: nowo_uptime_monitor` (since 1.9.1): the bundle routes, as `Resources/config/routes.yaml` declares
 * them, with the tenant inside the host's own URLs when `dashboard.path` holds `{tenantSlug}` — e.g.
 * `/projects/{tenantSlug}/uptime` gives `/projects/{tenantSlug}/uptime/monitors/new` instead of
 * `/uptime/{tenantSlug}/monitors/new`. The tenant-less pages (tenant list, new tenant) are left out in that mode: the
 * host owns its tenants. Without the placeholder the routes are those of `routes.yaml`.
 */
final class UptimeMonitorRouteLoader extends Loader
{
    public const TYPE         = 'nowo_uptime_monitor';
    private const PLACEHOLDER = '{tenantSlug}';

    /** `routes.yaml` prefixes the admin routes with this parameter; the router resolves it after loading. */
    private const PATH_PARAMETER = '%nowo_uptime_monitor.dashboard.path%';

    public function __construct(
        #[Autowire('%nowo_uptime_monitor.dashboard.path%')]
        private readonly string $dashboardPath,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        /** @var RouteCollection $routes */
        $routes = $this->import('@UptimeMonitorBundle/Resources/config/routes.yaml');
        if (!str_contains($this->dashboardPath, self::PLACEHOLDER)) {
            return $routes;
        }

        $prefix = rtrim($this->dashboardPath, '/');
        foreach ($routes->all() as $name => $route) {
            $path = $route->getPath();
            if (str_starts_with(ltrim($path, '/'), self::PATH_PARAMETER)) {
                $tail = substr(ltrim($path, '/'), strlen(self::PATH_PARAMETER));
            } elseif (str_starts_with($path, $prefix)) {
                $tail = substr($path, strlen($prefix));
            } else {
                continue;
            }
            $inner = strpos($tail, '/' . self::PLACEHOLDER);
            if ($inner === false) {
                // Under the admin prefix without a tenant of its own: the tenant list / new tenant pages.
                // @igor-ignore - Route collection built by this load() call at cache warm-up; not request state.
                $routes->remove($name);
                continue;
            }
            // @igor-ignore - Route collection built by this load() call at cache warm-up; not request state.
            $route->setPath($prefix . substr_replace($tail, '', $inner, strlen('/' . self::PLACEHOLDER)));
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
