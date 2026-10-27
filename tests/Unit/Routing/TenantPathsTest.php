<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Routing;

use Nowo\UptimeMonitorBundle\Routing\TenantPaths;
use Nowo\UptimeMonitorBundle\Routing\TenantPathUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

final class TenantPathsTest extends TestCase
{
    public function testTheSlugPicksTheCopyAndTheCopyNamesItsBundleRoute(): void
    {
        $paths = $this->paths();

        self::assertSame('personal_', $paths->namePrefixFor('user-1'));
        self::assertNull($paths->namePrefixFor('project-1'));
        self::assertSame('nowo_uptime_dashboard', $paths->bundleRoute('personal_nowo_uptime_dashboard'));
        self::assertSame('nowo_uptime_dashboard', $paths->bundleRoute('nowo_uptime_dashboard'));
        self::assertSame('personal_other', $paths->bundleRoute('personal_other'));
    }

    public function testLinksOfAClaimedSlugUseTheCopy(): void
    {
        $router = new TenantPathUrlGenerator($this->router(), $this->paths());

        self::assertSame('/uptime/user-1', $router->generate('nowo_uptime_dashboard', ['tenantSlug' => 'user-1']));
        self::assertSame('/projects/p-1/uptime', $router->generate('nowo_uptime_dashboard', ['tenantSlug' => 'p-1']));
        self::assertSame('/status/user-1', $router->generate('nowo_uptime_status_page', ['tenantSlug' => 'user-1']), 'no copy');
        self::assertSame('/other?tenantSlug=user-1', $router->generate('other', ['tenantSlug' => 'user-1']));

        $context = new RequestContext();
        $router->setContext($context);
        self::assertSame($context, $router->getContext());
        self::assertCount(4, $router->getRouteCollection());
        self::assertSame('other', $router->match('/other')['_route']);
        self::assertSame('other', $router->matchRequest(Request::create('/other'))['_route']);
        self::assertSame(['warmed'], $router->warmUp(sys_get_temp_dir()));
    }

    private function paths(): TenantPaths
    {
        return new TenantPaths([['slug_prefix' => 'user-', 'path' => '/uptime/{tenantSlug}', 'name_prefix' => 'personal_', 'slug_requirement' => null]]);
    }

    private function router(): Router&WarmableInterface
    {
        return new class(new ClosureLoader(), static function (): RouteCollection {
            $routes = new RouteCollection();
            $routes->add('nowo_uptime_dashboard', new Route('/projects/{tenantSlug}/uptime'));
            $routes->add('personal_nowo_uptime_dashboard', new Route('/uptime/{tenantSlug}'));
            $routes->add('nowo_uptime_status_page', new Route('/status/{tenantSlug}'));
            $routes->add('other', new Route('/other'));

            return $routes;
        }) extends Router implements WarmableInterface {

            public function warmUp(string $cacheDir, ?string $buildDir = null): array
            {
                return ['warmed'];
            }
        };
    }
}
