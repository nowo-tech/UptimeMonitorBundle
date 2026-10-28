<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Controller\MonitorController;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Push\PushCredentials;
use Nowo\UptimeMonitorBundle\Push\PushMetricsSeries;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\ControllerContainerTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use Nowo\UptimeMonitorBundle\Ui\UptimePages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function hash;

#[CoversClass(MonitorController::class)]
final class MonitorControllerPushTest extends TestCase
{
    use ControllerContainerTrait;
    use EntityIdTrait;
    use SyncDispatcherTestTrait;

    private PushCredentials $credentials;

    protected function setUp(): void
    {
        $this->credentials = new PushCredentials('kernel-secret');
    }

    private function controller(Monitor $monitor, ?EntityManagerInterface $em = null): MonitorController
    {
        $this->setEntityId($monitor, 7);
        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findOneBySlug')->willReturn($monitor->getTenant());
        $monitorRepo = $this->createMock(MonitorRepository::class);
        $monitorRepo->method('find')->willReturn($monitor);
        $aggregates = $this->getMockBuilder(CheckAggregateRepository::class)->onlyMethods(['findForMonitorInRange'])->disableOriginalConstructor()->getMock();
        $aggregates->method('findForMonitorInRange')->willReturn([]);
        $results = $this->createMock(CheckResultRepository::class);
        $results->method('findChecksForMonitorSince')->willReturn([]);

        return new MonitorController(
            $this->createMock(TranslatorInterface::class),
            $tenantRepo,
            $monitorRepo,
            $results,
            $this->monitorFactory($monitorRepo),
            new AggregateChartService($aggregates),
            $this->dashboardViewBuilder($monitorRepo),
            $em ?? $this->createMock(EntityManagerInterface::class),
            new UptimePages(),
            pushCredentials: $this->credentials,
            pushSeries: new PushMetricsSeries($results),
        );
    }

    private function pushMonitor(): Monitor
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'web-01', MonitorType::Push, 'push');
        PushCredentials::ensureKey($monitor);

        return $monitor;
    }

    public function testTheShowPageOfAPushMonitorCarriesTheServerHealthView(): void
    {
        $monitor = $this->pushMonitor();
        $monitor->recordPush(new DateTimeImmutable(), ['cpu_pct' => 5.0]);
        $controller = $this->controller($monitor);
        $this->bindController($controller, true, Request::create('/main/monitors/7?range=6'));

        $controller->show('main', 7, Request::create('/main/monitors/7?range=6'));
        $push = $this->renderedContext['push'];

        self::assertIsArray($push);
        self::assertSame('/generated/nowo_uptime_push', $push['url']);
        self::assertFalse($push['has_token']);
        self::assertFalse($push['signed']);
        self::assertSame(60, $push['grace']);
        self::assertSame(6, $push['series']['hours']);
        self::assertSame([['key' => 'cpu', 'value' => 5.0, 'level' => 'ok']], $push['gauges']['metrics']);
        self::assertArrayNotHasKey('push_secret', $this->renderedContext);
    }

    public function testOtherMonitorsHaveNoServerHealthView(): void
    {
        $controller = $this->controller(new Monitor(new Tenant('main', 'Main'), 'api', MonitorType::Https, 'https://x.test'));
        $this->bindController($controller);
        $controller->show('main', 7);

        self::assertNull($this->renderedContext['push']);
    }

    public function testGeneratingATokenShowsItOnceUncachedAndStoresOnlyItsHash(): void
    {
        $monitor = $this->pushMonitor();
        $monitor->setConfig(['push_signed' => true]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $request    = Request::create('/main/monitors/7/push-token', 'POST', ['_token' => 'valid']);
        $controller = $this->controller($monitor, $em);
        $this->bindController($controller, true, $request);

        $response = $controller->pushToken('main', 7, $request);
        $token    = $this->renderedContext['push_secret'];

        self::assertIsString($token);
        self::assertSame(hash('sha256', $token), $monitor->getPushTokenHash());
        self::assertSame($this->credentials->signingSecret($monitor), $this->renderedContext['push_signing_secret']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertTrue($this->renderedContext['push']['has_token']);
    }

    public function testAnUnsignedMonitorGetsNoSigningSecret(): void
    {
        $monitor    = $this->pushMonitor();
        $request    = Request::create('/main/monitors/7/push-token', 'POST', ['_token' => 'valid']);
        $controller = $this->controller($monitor);
        $this->bindController($controller, true, $request);
        $controller->pushToken('main', 7, $request);

        self::assertNull($this->renderedContext['push_signing_secret']);
    }

    public function testAnInvalidCsrfTokenIssuesNothing(): void
    {
        $monitor    = $this->pushMonitor();
        $request    = Request::create('/main/monitors/7/push-token', 'POST', ['_token' => 'bad']);
        $controller = $this->controller($monitor);
        $this->bindController($controller, false, $request);

        self::assertTrue($controller->pushToken('main', 7, $request)->isRedirect());
        self::assertNull($monitor->getPushTokenHash());
    }

    public function testRevokingDropsTheToken(): void
    {
        $monitor = $this->pushMonitor();
        $this->credentials->issueToken($monitor);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $request    = Request::create('/main/monitors/7/push-revoke', 'POST', ['_token' => 'valid']);
        $controller = $this->controller($monitor, $em);
        $this->bindController($controller, true, $request);

        self::assertTrue($controller->pushRevoke('main', 7, $request)->isRedirect());
        self::assertNull($monitor->getPushTokenHash());
    }

    public function testRevokingWithAnInvalidCsrfTokenKeepsIt(): void
    {
        $monitor = $this->pushMonitor();
        $this->credentials->issueToken($monitor);
        $request    = Request::create('/main/monitors/7/push-revoke', 'POST', ['_token' => 'bad']);
        $controller = $this->controller($monitor);
        $this->bindController($controller, false, $request);
        $controller->pushRevoke('main', 7, $request);

        self::assertNotNull($monitor->getPushTokenHash());
    }

    public function testTokenActionsAre404ForOtherMonitors(): void
    {
        $controller = $this->controller(new Monitor(new Tenant('main', 'Main'), 'api', MonitorType::Https, 'https://x.test'));
        $request    = Request::create('/main/monitors/7/push-token', 'POST', ['_token' => 'valid']);
        $this->bindController($controller, true, $request);

        $this->expectException(NotFoundHttpException::class);
        $controller->pushToken('main', 7, $request);
    }
}
