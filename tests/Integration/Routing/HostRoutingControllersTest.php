<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Routing;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UptimeMonitorBundle\Tests\Support\HostRoutingKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function is_string;
use function str_starts_with;

/**
 * REQ-SF-004 rule 7: the bundle routes are opt-in. A host whose routing only holds the Symfony default
 * `routing.controllers` resource must not get any bundle route; the documented import still serves the controllers.
 *
 * The full kernel boot triggers third-party deprecations on mixed CI cells (FrameworkBundle 7.4 calling
 * RegisterListenersPass::setHotPathEvents() / setNoPreloadEvents(), deprecated in symfony/event-dispatcher 8.1), so
 * the deprecation gate is relaxed for these two tests only; the bundle code they cover is gated by the other tests.
 */
#[CoversNothing]
#[IgnoreDeprecations]
final class HostRoutingControllersTest extends TestCase
{
    private ?HostRoutingKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    public function testRoutingControllersResourceDoesNotLoadTheBundleRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        $bundleRoutes = [];
        foreach ($this->router(false)->getRouteCollection()->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (str_starts_with($name, 'nowo_uptime') || (is_string($controller) && str_starts_with($controller, 'Nowo\\UptimeMonitorBundle\\'))) {
                $bundleRoutes[] = $name;
            }
        }

        self::assertSame([], $bundleRoutes, 'The bundle routes must only exist when the host imports them.');
    }

    public function testImportedRoutesServeTheControllers(): void
    {
        $router = $this->router(true);
        self::assertSame('/api/uptime/{tenantSlug}/summary', $router->getRouteCollection()->get('nowo_uptime_api_summary')?->getPath());
        self::assertSame('/uptime/tenants', $router->getRouteCollection()->get('nowo_uptime_tenant_index')?->getPath());

        $kernel = $this->kernel;
        self::assertNotNull($kernel);
        $entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // AbstractController::json() goes through the controller's service locator (container.service_subscriber).
        $response = $kernel->handle(Request::create('/api/uptime/unknown/summary'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error":"Tenant not found"}', $response->getContent());
    }

    private function router(bool $importBundleRoutes): RouterInterface
    {
        $this->kernel = new HostRoutingKernel($importBundleRoutes);
        $this->kernel->boot();
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }
}
