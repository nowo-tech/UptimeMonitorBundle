<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Routing;

use Nowo\UptimeMonitorBundle\Routing\UptimeMonitorRouteLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class UptimeMonitorRouteLoaderTest extends TestCase
{
    public function testWithoutPlaceholderTheRoutesAreThoseOfRoutesYaml(): void
    {
        $routes = $this->loader('/uptime')->load('.', UptimeMonitorRouteLoader::TYPE);

        self::assertSame('/%nowo_uptime_monitor.dashboard.path%/{tenantSlug}/monitors/new', $routes->get('nowo_uptime_monitor_new')?->getPath());
        self::assertNotNull($routes->get('nowo_uptime_tenant_index'));
    }

    public function testThePlaceholderPutsTheTenantInsideTheHostUrls(): void
    {
        $routes = $this->loader('/projects/{tenantSlug}/uptime/')->load('.', UptimeMonitorRouteLoader::TYPE);

        self::assertSame('/projects/{tenantSlug}/uptime', $routes->get('nowo_uptime_dashboard')?->getPath());
        self::assertSame('/projects/{tenantSlug}/uptime/monitors/new', $routes->get('nowo_uptime_monitor_new')?->getPath());
        self::assertSame('/projects/{tenantSlug}/uptime/settings/general', $routes->get('nowo_uptime_settings_general')?->getPath());
        self::assertSame('/projects/{tenantSlug}/uptime/monitors/{id}', $routes->get('nowo_uptime_monitor_show')?->getPath(), 'an already resolved prefix');
        self::assertNull($routes->get('nowo_uptime_tenant_index'), 'the host owns its tenants');
        self::assertSame('/status/{tenantSlug}', $routes->get('nowo_uptime_status_page')?->getPath());
        self::assertSame('/api/uptime/{tenantSlug}/summary', $routes->get('nowo_uptime_api_summary')?->getPath());
    }

    public function testTenantPathsCopyTheTenantRoutes(): void
    {
        $routes = $this->loader('/projects/{tenantSlug}/uptime', [
            ['slug_prefix' => 'user-', 'path' => '/uptime/{tenantSlug}/', 'name_prefix' => 'personal_', 'slug_requirement' => null],
            ['slug_prefix' => 'team-', 'path' => '/teams/{tenantSlug}/uptime', 'name_prefix' => 'team_', 'slug_requirement' => 'team-\\d+'],
        ])->load('.', UptimeMonitorRouteLoader::TYPE);

        self::assertSame('/uptime/{tenantSlug}/monitors/new', $routes->get('personal_nowo_uptime_monitor_new')?->getPath());
        $personal = $routes->get('personal_nowo_uptime_dashboard');
        self::assertSame(['/uptime/{tenantSlug}', 'user\\-[a-z0-9\\-]+'], [$personal?->getPath(), $personal?->getRequirement('tenantSlug')]);
        $team = $routes->get('team_nowo_uptime_monitor_show');
        self::assertSame(['/teams/{tenantSlug}/uptime/monitors/{id}', 'team-\\d+'], [$team?->getPath(), $team?->getRequirement('tenantSlug')]);
        self::assertNull($routes->get('personal_nowo_uptime_status_page'), 'not a tenant page under the dashboard path');
        self::assertSame('/projects/{tenantSlug}/uptime/monitors/new', $routes->get('nowo_uptime_monitor_new')?->getPath(), 'the default path stays');
    }

    public function testItHandlesItsOwnImportTypeOnly(): void
    {
        $loader = $this->loader('/uptime');

        self::assertTrue($loader->supports('.', UptimeMonitorRouteLoader::TYPE));
        self::assertFalse($loader->supports('.', 'attribute'));
    }

    /**
     * @param list<array{slug_prefix: string, path: string, name_prefix: string, slug_requirement: string|null}> $tenantPaths
     */
    private function loader(string $dashboardPath, array $tenantPaths = []): UptimeMonitorRouteLoader
    {
        $bundleRoutes = new class extends Loader {
            public function load(mixed $resource, ?string $type = null): RouteCollection
            {
                $routes = new RouteCollection();
                $prefix = '/%nowo_uptime_monitor.dashboard.path%';
                $routes->add('nowo_uptime_tenant_index', new Route($prefix . '/tenants'));
                $routes->add('nowo_uptime_dashboard', new Route($prefix . '/{tenantSlug}'));
                $routes->add('nowo_uptime_monitor_new', new Route($prefix . '/{tenantSlug}/monitors/new'));
                $routes->add('nowo_uptime_settings_general', new Route($prefix . '/{tenantSlug}/settings/general'));
                $routes->add('nowo_uptime_monitor_show', new Route('/projects/{tenantSlug}/uptime/{tenantSlug}/monitors/{id}'));
                $routes->add('nowo_uptime_status_page', new Route('/status/{tenantSlug}'));
                $routes->add('nowo_uptime_api_summary', new Route('/api/uptime/{tenantSlug}/summary'));

                return $routes;
            }

            public function supports(mixed $resource, ?string $type = null): bool
            {
                return $resource === '@UptimeMonitorBundle/Resources/config/routes.yaml';
            }
        };
        $loader = new UptimeMonitorRouteLoader($dashboardPath, null, $tenantPaths);
        $loader->setResolver(new LoaderResolver([$bundleRoutes, $loader]));

        return $loader;
    }
}
