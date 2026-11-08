<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Routing;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Tests\Support\HostRoutingKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * UM-7: `security.denied_tenant_status` through a real kernel, with a monitor-aware checker that closes `user-*`
 * tenants except their shared monitors. HTML pages and JSON APIs answer alike; the shared monitor stays readable.
 */
#[CoversNothing]
#[IgnoreDeprecations]
final class DeniedTenantStatusKernelTest extends TestCase
{
    private ?HostRoutingKernel $kernel = null;

    /** @var array<string, int> */
    private array $ids = [];

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    private function boot(?int $deniedTenantStatus): void
    {
        $security = ['access_checker' => 'test.shared_monitor_access_checker'];
        if ($deniedTenantStatus !== null) {
            $security['denied_tenant_status'] = $deniedTenantStatus;
        }
        $this->kernel = new HostRoutingKernel(true, [], $security);
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $personal = new Tenant('user-a', 'Ana');
        $project  = new Tenant('main', 'Main');
        $monitors = [
            'shared'  => new Monitor($personal, 'Shared API', MonitorType::Https, 'https://shared.test/'),
            'private' => new Monitor($personal, 'Private API', MonitorType::Https, 'https://private.test/'),
            'project' => new Monitor($project, 'Project API', MonitorType::Https, 'https://project.test/'),
        ];
        $em->persist($personal);
        $em->persist($project);
        foreach ($monitors as $monitor) {
            $em->persist($monitor);
        }
        $em->flush();
        foreach ($monitors as $key => $monitor) {
            $this->ids[$key] = (int) $monitor->getId();
        }
    }

    private function statusOf(string $uri, string $method = 'GET'): int
    {
        self::assertInstanceOf(HostRoutingKernel::class, $this->kernel);
        $request = Request::create($uri, $method);
        $request->headers->set('Accept', str_contains($uri, '/api/') ? 'application/json' : 'text/html');

        return $this->kernel->handle($request)->getStatusCode();
    }

    public function testATenantTheUserMayNotAccessIsNotFoundOnPagesAndApis(): void
    {
        $this->boot(404);
        $shared  = $this->ids['shared'];
        $private = $this->ids['private'];

        foreach ([
            '/uptime/user-a',
            '/uptime/user-a/summary',
            '/uptime/user-a/settings',
            '/uptime/user-a/monitors/new',
            '/uptime/user-a/monitors/' . $shared . '/edit',
            '/uptime/user-a/monitors/' . $private,
            '/api/uptime/user-a/summary',
            '/api/uptime/user-a/monitors/' . $private . '/history',
        ] as $uri) {
            self::assertSame(404, $this->statusOf($uri), $uri);
        }
        self::assertSame(404, $this->statusOf('/uptime/user-a/monitors/' . $shared . '/toggle-pause', 'POST'));
    }

    public function testTheSharedMonitorStaysReadable(): void
    {
        $this->boot(404);
        $shared = $this->ids['shared'];

        self::assertSame(200, $this->statusOf('/uptime/user-a/monitors/' . $shared));
        self::assertSame(200, $this->statusOf('/api/uptime/user-a/monitors/' . $shared . '/history'));
    }

    public function testAMemberRefusedOneActionKeeps403(): void
    {
        $this->boot(404);

        self::assertSame(200, $this->statusOf('/uptime/main'));
        self::assertSame(403, $this->statusOf('/uptime/main/monitors/' . $this->ids['project'] . '/edit'));
        self::assertSame(403, $this->statusOf('/uptime/main/settings'));
    }

    public function testTheDefaultKeeps403(): void
    {
        $this->boot(null);

        self::assertSame(403, $this->statusOf('/uptime/user-a'));
        self::assertSame(403, $this->statusOf('/api/uptime/user-a/summary'));
        self::assertSame(404, $this->statusOf('/uptime/user-a/monitors/' . $this->ids['private']), 'a monitor read was already 404 (1.21)');
        self::assertSame(200, $this->statusOf('/uptime/user-a/monitors/' . $this->ids['shared']));
    }
}
