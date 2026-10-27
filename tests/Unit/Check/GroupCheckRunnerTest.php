<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\GroupCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Tests\Unit\Support\EntityIdTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupCheckRunner::class)]
final class GroupCheckRunnerTest extends TestCase
{
    use EntityIdTrait;

    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant('main', 'Main');
    }

    private function monitor(int $id, MonitorType $type = MonitorType::Https): Monitor
    {
        $monitor = new Monitor($this->tenant, 'm' . $id, $type, 'https://m.test');
        $this->setEntityId($monitor, $id);

        return $monitor;
    }

    /**
     * @param list<Monitor> $children
     * @param array<int, CheckResult> $latest
     */
    private function runner(array $children, array $latest = []): GroupCheckRunner
    {
        $monitors = $this->createStub(MonitorRepository::class);
        $monitors->method('findChildrenOf')->willReturn($children);
        $results = $this->createStub(CheckResultRepository::class);
        $results->method('findLatestByMonitorIds')->willReturn($latest);

        return new GroupCheckRunner($monitors, $results);
    }

    public function testSupportsOnlyGroups(): void
    {
        self::assertTrue($this->runner([])->supports($this->monitor(1, MonitorType::Group)));
        self::assertFalse($this->runner([])->supports($this->monitor(1)));
    }

    public function testUnsavedGroupAndEmptyGroupAreUnknown(): void
    {
        $unsaved = $this->runner([])->run(new Monitor($this->tenant, 'g', MonitorType::Group, 'g'));
        self::assertSame(CheckStatus::Unknown, $unsaved->status);
        self::assertSame('Group monitor is not persisted yet.', $unsaved->message);

        $empty = $this->runner([])->run($this->monitor(1, MonitorType::Group));
        self::assertSame('No child monitors in this group.', $empty->message);
    }

    public function testRollsUpTheWorstChildStatusAndMaxLatency(): void
    {
        $a = $this->monitor(2);
        $b = $this->monitor(3);
        $c = $this->monitor(4);
        $d = $this->monitor(5);

        $result = $this->runner([$a, $b, $c, $d], [
            2 => new CheckResult($a, CheckStatus::Up, 15),
            3 => new CheckResult($b, CheckStatus::Degraded, 80),
            4 => new CheckResult($c, CheckStatus::Up, 30),
        ])->run($this->monitor(1, MonitorType::Group));

        self::assertSame(CheckStatus::Degraded, $result->status);
        self::assertSame(80, $result->latencyMs);
        self::assertSame('2/4 child monitor(s) up', $result->message);
        self::assertSame(['children_total' => 4, 'children_up' => 2], $result->metadata);
    }

    public function testDownWinsOverEverything(): void
    {
        $a = $this->monitor(2);
        $b = $this->monitor(3);

        $result = $this->runner([$a, $b], [
            2 => new CheckResult($a, CheckStatus::Down, 5),
            3 => new CheckResult($b, CheckStatus::Up, 5),
        ])->run($this->monitor(1, MonitorType::Group));

        self::assertSame(CheckStatus::Down, $result->status);
    }

    public function testAllUpIsUp(): void
    {
        $a = $this->monitor(2);

        self::assertSame(
            CheckStatus::Up,
            $this->runner([$a], [2 => new CheckResult($a, CheckStatus::Up, 5)])->run($this->monitor(1, MonitorType::Group))->status,
        );
    }
}
