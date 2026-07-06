<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\SyncDispatcherTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

use function in_array;

/**
 * @covers \Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher
 */
final class DashboardSyncDispatcherTest extends TestCase
{
    use SyncDispatcherTestTrait;

    public function testDispatchPublishesWhenMercureEnabled(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(static function (Update $update): bool {
                return str_contains($update->getData(), 'monitor_update')
                    && in_array('/uptime/main', $update->getTopics(), true);
            }));

        $tenant  = new Tenant('main', 'Main');
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://x.test');
        $result  = new \Nowo\UptimeMonitorBundle\Entity\CheckResult($monitor, CheckStatus::Up, 10);

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
        $result  = new \Nowo\UptimeMonitorBundle\Entity\CheckResult($monitor, CheckStatus::Up, 10);

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
            null,
        );

        self::assertSame('/uptime/acme', $dispatcher->resolveTopic('acme'));
    }
}
