<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\StatusSummary;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusSummary::class)]
final class StatusSummaryTest extends TestCase
{
    use EntityIdTrait;

    public function testActiveMonitorsWithTheirLatestCheck(): void
    {
        $tenant   = new Tenant('main', 'Main');
        $api      = $this->monitor($tenant, 'API', MonitorType::Https, 1);
        $paused   = $this->monitor($tenant, 'Old', MonitorType::Https, 2)->setPaused(true);
        $group    = $this->monitor($tenant, 'Team', MonitorType::Group, 3);
        $db       = $this->monitor($tenant, 'DB', MonitorType::Tcp, 4);
        $monitors = $this->createStub(MonitorRepository::class);
        $monitors->method('findByTenantSlug')->willReturn([$api, $paused, $group, $db]);
        $checks = $this->createMock(CheckResultRepository::class);
        $up     = new CheckResult($api, CheckStatus::Up, 12);
        $checks->expects(self::once())->method('findLatestByMonitorIds')->with([1, 4])->willReturn([1 => $up]);

        $rows = (new StatusSummary($monitors, $checks))->rows('main');

        self::assertSame([['monitor' => $api, 'latest' => $up], ['monitor' => $db, 'latest' => null]], $rows);
        self::assertSame(['down' => 0, 'degraded' => 0, 'unknown' => 1, 'up' => 1], StatusSummary::counts($rows));
        self::assertSame('unknown', StatusSummary::overall($rows));
    }

    public function testTheWorstStatusDecides(): void
    {
        $monitor = $this->monitor(new Tenant('main', 'Main'), 'API', MonitorType::Https, 1);
        $row     = static fn (CheckStatus $status): array => ['monitor' => $monitor, 'latest' => new CheckResult($monitor, $status, 1)];

        self::assertSame('unknown', StatusSummary::overall([]));
        self::assertSame('operational', StatusSummary::overall([$row(CheckStatus::Up)]));
        self::assertSame('degraded', StatusSummary::overall([$row(CheckStatus::Up), $row(CheckStatus::Degraded)]));
        self::assertSame('major', StatusSummary::overall([$row(CheckStatus::Degraded), $row(CheckStatus::Down)]));
    }

    private function monitor(Tenant $tenant, string $name, MonitorType $type, int $id): Monitor
    {
        $monitor = new Monitor($tenant, $name, $type, 'https://x.test');
        $this->setEntityId($monitor, $id);

        return $monitor;
    }
}
