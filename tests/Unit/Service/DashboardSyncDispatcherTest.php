<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

use function in_array;

#[CoversClass(DashboardSyncDispatcher::class)]
final class DashboardSyncDispatcherTest extends TestCase
{
    use SyncDispatcherTestTrait;

    public function testDispatchPublishesWhenMercureEnabled(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(static fn (Update $update): bool => str_contains($update->getData(), 'monitor_update')
                && in_array('/uptime/main', $update->getTopics(), true)));

        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $result  = new CheckResult($monitor, CheckStatus::Up, 10);

        $dispatcher = new DashboardSyncDispatcher(
            $this->summaryPayloadBuilder(),
            [
                'sync'    => 'mercure',
                'mercure' => ['topic_template' => '/uptime/{tenant}', 'private' => true],
            ],
            $hub,
        );

        $dispatcher->dispatchAfterCheck($monitor, $result);
    }

    public function testDispatchSkipsWhenPollingMode(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $result  = new CheckResult($monitor, CheckStatus::Up, 10);

        $dispatcher = new DashboardSyncDispatcher(
            $this->summaryPayloadBuilder(),
            ['sync' => 'polling'],
            $hub,
        );

        $dispatcher->dispatchAfterCheck($monitor, $result);
    }

    public function testResolveTopic(): void
    {
        $dispatcher = new DashboardSyncDispatcher(
            $this->summaryPayloadBuilder(),
            ['sync' => 'mercure', 'mercure' => ['topic_template' => '/uptime/{tenant}']],
        );

        self::assertSame('/uptime/acme', $dispatcher->resolveTopic('acme'));
    }

    public function testTenantRefreshPublishesAResetPayload(): void
    {
        $published = [];
        $hub       = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = $update;

            return 'id';
        });

        $dispatcher = new DashboardSyncDispatcher(
            $this->summaryPayloadBuilder(),
            ['sync' => 'mercure', 'mercure' => ['topic_template' => '/t/{tenant}', 'private' => false]],
            $hub,
        );

        self::assertTrue($dispatcher->isMercureEnabled());
        $dispatcher->dispatchTenantRefresh('main');

        self::assertCount(1, $published);
        self::assertSame(['/t/main'], $published[0]->getTopics());
        self::assertFalse($published[0]->isPrivate());
        self::assertStringContainsString('"type":"dashboard_reset"', $published[0]->getData());
    }

    public function testNothingIsPublishedWithoutMercureOrHub(): void
    {
        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $result  = new CheckResult($monitor, CheckStatus::Up, 10);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $polling = new DashboardSyncDispatcher($this->summaryPayloadBuilder(), [], $hub);
        $polling->dispatchTenantRefresh('main');
        self::assertFalse($polling->isMercureEnabled());

        $noHub = new DashboardSyncDispatcher($this->summaryPayloadBuilder(), ['sync' => 'mercure']);
        $noHub->dispatchTenantRefresh('main');
        $noHub->dispatchAfterCheck($monitor, $result);
        self::assertFalse($noHub->isMercureEnabled());
        self::assertSame('/uptime/main', $noHub->resolveTopic('main'));
    }
}
