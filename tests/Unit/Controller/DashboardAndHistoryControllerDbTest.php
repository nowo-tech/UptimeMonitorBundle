<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Controller\Api\HistoryApiController;
use Nowo\UptimeMonitorBundle\Controller\DashboardController;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Nowo\UptimeMonitorBundle\Service\SummaryPayloadBuilder;
use Nowo\UptimeMonitorBundle\Service\TenantDashboardSerializer;
use Nowo\UptimeMonitorBundle\Service\UptimeMetricsService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

use function json_decode;
use function method_exists;

/**
 * Dashboard page / layout fragment (events filter, Mercure) and the per-monitor history API on a real SQLite schema.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(HistoryApiController::class)]
final class DashboardAndHistoryControllerDbTest extends TestCase
{
    use ControllerContainerTrait;

    private EntityManagerInterface $em;
    private MonitorRepository $monitors;
    private CheckResultRepository $results;
    private CheckAggregateRepository $aggregates;
    private TenantRepository $tenants;
    private Monitor $api;
    private Monitor $foreign;

    protected function setUp(): void
    {
        $this->em = SqliteEntityManagerFactory::create();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        $this->monitors   = new MonitorRepository($registry);
        $this->results    = new CheckResultRepository($registry);
        $this->aggregates = new CheckAggregateRepository($registry);
        $this->tenants    = new TenantRepository($registry);

        $main          = new Tenant('main', 'Main');
        $other         = new Tenant('other', 'Other');
        $this->api     = new Monitor($main, 'API', MonitorType::Https, 'https://api.test');
        $this->foreign = new Monitor($other, 'Theirs', MonitorType::Https, 'https://theirs.test');
        foreach ([$main, $other, $this->api, $this->foreign] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new CheckResult($this->api, CheckStatus::Up, 12));
        $this->em->flush();
    }

    private function viewBuilder(): DashboardViewBuilder
    {
        return new DashboardViewBuilder($this->monitors, $this->results, $this->aggregates, new UptimeMetricsService(), new TenantDashboardSerializer());
    }

    private function dashboard(string $sync, ?HubInterface $hub = null, ?Authorization $authorization = null): DashboardController
    {
        $controller = new DashboardController(
            $this->tenants,
            $this->monitors,
            $this->viewBuilder(),
            new AggregateChartService($this->aggregates),
            ['sync' => $sync, 'mercure' => ['topic_template' => '/uptime/{tenant}']],
            new DashboardSyncDispatcher(
                new SummaryPayloadBuilder($this->monitors, $this->results, new UptimeMetricsService(), new TenantDashboardSerializer()),
                ['sync' => $sync],
                $hub,
            ),
            $hub,
            $authorization,
        );
        $this->bindController($controller, true, new Request());

        return $controller;
    }

    public function testMercureDashboardSetsTheSubscriberCookie(): void
    {
        $factory = $this->createStub(TokenFactoryInterface::class);
        $factory->method('create')->willReturn('jwt');
        $hub = $this->createStub(HubInterface::class);
        $hub->method('getPublicUrl')->willReturn('https://example.test/.well-known/mercure');
        $hub->method('getFactory')->willReturn($factory);
        // @phpstan-ignore function.alreadyNarrowedType (false with the symfony/mercure 0.7 CI cell)
        if (method_exists(HubInterface::class, 'getCookieName')) {
            // symfony/mercure 0.8+; 0.7 derives the cookie name itself.
            $hub->method('getCookieName')->willReturn('mercureAuthorization');
        }

        $request  = Request::create('https://example.test/main');
        $response = $this->dashboard('mercure', $hub, new Authorization(new HubRegistry($hub)))->index('main', $request);

        self::assertSame('rendered', $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertTrue($request->attributes->has('_mercure_authorization_cookies'));
    }

    public function testMercureWithoutHubStillRenders(): void
    {
        self::assertSame('rendered', $this->dashboard('mercure')->index('main', new Request())->getContent());
    }

    public function testLayoutFragmentHonoursTheEventsFilter(): void
    {
        $controller = $this->dashboard('polling');

        foreach ([(int) $this->api->getId(), (int) $this->foreign->getId(), 999] as $monitorId) {
            $response = $controller->layoutFragment('main', new Request(['monitor' => $monitorId]));
            self::assertSame('rendered', $response->getContent());
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function testLayoutFragmentForAnUnknownTenantIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Tenant "nope" not found.');
        $this->dashboard('polling')->layoutFragment('nope', new Request());
    }

    public function testHistoryApi(): void
    {
        $controller = new HistoryApiController($this->tenants, $this->monitors, $this->results, $this->viewBuilder());
        $this->bindController($controller);

        $ok      = $controller->monitorHistory('main', (int) $this->api->getId());
        $payload = json_decode((string) $ok->getContent(), true);
        self::assertSame('up', $payload['last_status'] ?? null);
        self::assertSame((int) $this->api->getId(), $payload['monitor_id'] ?? null);
        self::assertEquals(100, $payload['uptime_24h'] ?? null);

        self::assertSame(404, $controller->monitorHistory('nope', 1)->getStatusCode());
        self::assertSame(404, $controller->monitorHistory('main', (int) $this->foreign->getId())->getStatusCode());
        self::assertSame(404, $controller->monitorHistory('main', 999)->getStatusCode());
    }
}
