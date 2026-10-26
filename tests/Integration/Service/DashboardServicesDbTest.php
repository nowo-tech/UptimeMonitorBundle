<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Repository\CheckResultRepository;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Service\AggregateChartService;
use Nowo\UptimeMonitorBundle\Service\DashboardViewBuilder;
use Nowo\UptimeMonitorBundle\Service\SummaryPayloadBuilder;
use Nowo\UptimeMonitorBundle\Service\TenantDashboardSerializer;
use Nowo\UptimeMonitorBundle\Service\UptimeMetricsService;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function array_column;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function sprintf;

/**
 * Dashboard read models over a real SQLite schema, with the clock frozen at {@see self::NOW}.
 */
#[Group('db')]
#[CoversClass(DashboardViewBuilder::class)]
#[CoversClass(SummaryPayloadBuilder::class)]
#[CoversClass(UptimeMetricsService::class)]
#[CoversClass(TenantDashboardSerializer::class)]
#[CoversClass(AggregateChartService::class)]
final class DashboardServicesDbTest extends TestCase
{
    private const NOW = '2026-05-10 12:00:00';

    private EntityManagerInterface $em;
    private MonitorRepository $monitors;
    private CheckResultRepository $checks;
    private CheckAggregateRepository $aggregates;
    private MockClock $clock;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em = SqliteEntityManagerFactory::create();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        $this->monitors   = new MonitorRepository($registry);
        $this->checks     = new CheckResultRepository($registry);
        $this->aggregates = new CheckAggregateRepository($registry);
        $this->clock      = new MockClock(self::NOW);
        $this->tenant     = new Tenant('main', 'Main');
        $this->em->persist($this->tenant);
    }

    private function monitor(string $name, MonitorType $type = MonitorType::Https, ?Monitor $parent = null, ?string $project = null): Monitor
    {
        $monitor = (new Monitor($this->tenant, $name, $type, 'https://' . $name . '.test'))->setParent($parent)->setProject($project);
        $this->em->persist($monitor);

        return $monitor;
    }

    private function check(Monitor $monitor, string $ago, CheckStatus $status = CheckStatus::Up, int $latency = 10): CheckResult
    {
        $result = new CheckResult($monitor, $status, $latency, 200, null, null, $this->clock->now()->modify($ago));
        $this->em->persist($result);

        return $result;
    }

    private function aggregate(Monitor $monitor, AggregatePeriod $period, string $ago, int $total, int $up, int $latency): CheckAggregate
    {
        $aggregate = new CheckAggregate($monitor, $period, $this->clock->now()->modify($ago));
        $aggregate->applyTotals($total, $up, $latency);
        $this->em->persist($aggregate);

        return $aggregate;
    }

    private function viewBuilder(): DashboardViewBuilder
    {
        return new DashboardViewBuilder(
            $this->monitors,
            $this->checks,
            $this->aggregates,
            new UptimeMetricsService(),
            new TenantDashboardSerializer(),
            $this->clock,
        );
    }

    private function summaryBuilder(): SummaryPayloadBuilder
    {
        return new SummaryPayloadBuilder($this->monitors, $this->checks, new UptimeMetricsService(), new TenantDashboardSerializer(), $this->clock);
    }

    /**
     * @return array{group: Monitor, child: Monitor, legacy: Monitor, other: Monitor, paused: Monitor, emptyGroup: Monitor}
     */
    private function seed(): array
    {
        $group      = $this->monitor('group', MonitorType::Group);
        $child      = $this->monitor('child', MonitorType::Https, $group);
        $legacy     = $this->monitor('legacy', MonitorType::Http, null, 'Shop');
        $other      = $this->monitor('other', MonitorType::Tcp);
        $paused     = $this->monitor('paused')->setPaused(true);
        $emptyGroup = $this->monitor('empty-group', MonitorType::Group);
        $this->em->flush();

        $this->check($child, '-2 hours', CheckStatus::Down, 40);
        $this->check($child, '-1 hour', CheckStatus::Up, 20);
        $this->check($group, '-1 hour', CheckStatus::Degraded);
        $this->check($legacy, '-30 minutes');
        $this->check($legacy, '-3 days', CheckStatus::Down);
        $this->aggregate($child, AggregatePeriod::Day, '-1 day', 10, 9, 30);
        $this->aggregate($child, AggregatePeriod::Hour, '-1 hour', 2, 1, 30);
        $this->aggregate($child, AggregatePeriod::Hour, '-2 hours', 0, 0, 0);
        $this->em->flush();

        return ['group' => $group, 'child' => $child, 'legacy' => $legacy, 'other' => $other, 'paused' => $paused, 'emptyGroup' => $emptyGroup];
    }

    public function testQuickStatsAndRecentEvents(): void
    {
        $m       = $this->seed();
        $builder = $this->viewBuilder();

        self::assertSame(['up' => 2, 'down' => 0, 'degraded' => 1, 'unknown' => 2, 'paused' => 1], $builder->buildQuickStats('main'));

        $tenantEvents = $builder->buildRecentEvents('main');
        self::assertCount(5, $tenantEvents);
        self::assertSame('legacy', $tenantEvents[0]['monitor_name']);
        self::assertTrue($tenantEvents[1]['is_group'] || $tenantEvents[2]['is_group']);

        $childEvents = $builder->buildRecentEvents('main', $m['child']->getId(), 10);
        self::assertSame(['up', 'down'], array_column($childEvents, 'status'));

        // An unknown monitor (or one of another tenant) falls back to the tenant log.
        self::assertCount(5, $builder->buildRecentEvents('main', 999_999));
        self::assertCount(0, $builder->buildRecentEvents('other-tenant', $m['child']->getId()));
    }

    public function testProjectGroupsNestChildrenAndGroupLegacyMonitorsByProject(): void
    {
        $this->seed();

        $sections = $this->viewBuilder()->buildProjectGroups('main');

        self::assertSame(['empty-group', 'group', 'General', 'Shop'], array_column($sections, 'label'));
        self::assertSame([], $sections[0]['children']);

        $group = $sections[1];
        self::assertSame('group', $group['group_monitor']?->getName());
        self::assertCount(1, $group['children']);
        $childRow = $group['children'][0];
        self::assertTrue($childRow['nested']);
        self::assertSame(50.0, $childRow['uptime_24h']);
        self::assertSame(90.0, $childRow['uptime_30d']);
        self::assertSame('up', $childRow['latest']?->getStatus()->value);
        self::assertCount(UptimeMetricsService::HISTORY_BAR_MAX_CHECKS, $childRow['history']);
        self::assertSame(['empty', 'down', 'up'], array_column(array_slice($childRow['history'], -3), 'status'));

        self::assertSame(['other', 'paused'], array_map(static fn (array $row): string => $row['monitor']->getName(), $sections[2]['children']));
        self::assertNull($sections[2]['group_row']);
        self::assertSame(100.0, $sections[3]['children'][0]['uptime_24h']);
        self::assertNull($sections[3]['children'][0]['uptime_30d']);
    }

    public function testMonitorDetailUsesHourlyAggregatesOrFallsBackToChecks(): void
    {
        $m       = $this->seed();
        $builder = $this->viewBuilder();

        $child = $builder->buildMonitorDetail($m['child']);
        self::assertSame(['11:00'], $child['latency_series']['labels']);
        self::assertSame([30], $child['latency_series']['latency_ms']);
        self::assertSame(['down', 'up'], array_column($child['events'], 'status'));
        self::assertSame(50.0, $child['uptime_24h']);
        self::assertSame(90.0, $child['uptime_30d']);
        self::assertSame([], $child['children']);

        $legacy = $builder->buildMonitorDetail($m['legacy']);
        self::assertSame(['11:30'], $legacy['latency_series']['labels']);
        self::assertNull($legacy['uptime_30d']);

        $group = $builder->buildMonitorDetail($m['group']);
        self::assertCount(1, $group['children']);
        self::assertSame('child', $group['children'][0]['monitor']->getName());
        self::assertSame('up', $group['children'][0]['latest']?->getStatus()->value);

        $empty = $builder->buildMonitorDetail($m['other']);
        self::assertSame([], $empty['latency_series']['labels']);
        self::assertNull($empty['uptime_24h']);
    }

    public function testTenantSummaryAndIncrementalSince(): void
    {
        $m       = $this->seed();
        $builder = $this->summaryBuilder();

        $full = $builder->buildTenantSummary('main');
        self::assertSame('2026-05-10T12:00:00+00:00', $full['server_time']);
        self::assertNull($full['since']);
        self::assertCount(6, $full['monitors']);
        self::assertArrayHasKey('stats', $full);
        self::assertArrayHasKey('events', $full);
        self::assertCount(5, $full['events']);

        $since = $builder->buildTenantSummary('main', $this->clock->now()->modify('-45 minutes'));
        self::assertArrayNotHasKey('stats', $since);
        // Monitors whose latest check is older than `since` are skipped; never-checked ones stay.
        self::assertSame(
            ['empty-group', 'legacy', 'other', 'paused'],
            array_map(static fn (array $row): string => $row['name'], $since['monitors']),
        );

        $children = array_values(array_filter($full['monitors'], static fn (array $row): bool => $row['name'] === 'child'));
        self::assertCount(1, $children);
        $child = $children[0];
        self::assertSame($m['group']->getId(), $child['parent_id']);
        self::assertSame('up', $child['last_status']);
        self::assertSame(50.0, $child['uptime_24h']);
    }

    public function testMonitorSummaryItemLoadsMissingDataAndKeepsTheLatestCheckInHistory(): void
    {
        $m       = $this->seed();
        $builder = $this->summaryBuilder();

        $loaded = $builder->buildMonitorSummaryItem($m['child']);
        self::assertSame('up', $loaded['last_status']);
        self::assertSame(['down', 'up'], array_column(array_slice($loaded['history'], -2), 'status'));

        // A latest check outside the 24h history window is appended to the bar.
        $old = $this->check($m['other'], '-2 days', CheckStatus::Down);
        $this->em->flush();
        $item = $builder->buildMonitorSummaryItem($m['other'], $old);
        self::assertSame('down', $item['history'][UptimeMetricsService::HISTORY_BAR_MAX_CHECKS - 1]['status']);

        // Appending the latest check to a full bar keeps only the newest HISTORY_BAR_MAX_CHECKS entries.
        $full = [];
        for ($i = UptimeMetricsService::HISTORY_BAR_MAX_CHECKS; $i > 0; --$i) {
            $full[] = $this->check($m['other'], sprintf('-%d minutes', $i));
        }
        $this->em->flush();
        $capped = $builder->buildMonitorSummaryItem($m['other'], $old, $full, $full);
        self::assertCount(UptimeMetricsService::HISTORY_BAR_MAX_CHECKS, $capped['history']);
        self::assertNotContains('down', array_column($capped['history'], 'status'), 'the older latest check is sliced off');
        self::assertSame(100.0, $capped['uptime_24h']);
    }

    public function testMercureUpdatePayload(): void
    {
        $m      = $this->seed();
        $latest = $this->checks->findLatestForMonitor($m['child']);
        self::assertNotNull($latest);

        $payload = $this->summaryBuilder()->buildMercureUpdatePayload($m['child'], $latest);

        self::assertSame('monitor_update', $payload['type']);
        self::assertSame('2026-05-10T12:00:00+00:00', $payload['server_time']);
        self::assertSame('child', $payload['monitor']['name']);
        self::assertSame('child', $payload['event']['monitor_name']);
        self::assertSame(1, $payload['stats']['paused']);
    }

    public function testAggregateCharts(): void
    {
        $m      = $this->seed();
        $charts = new AggregateChartService($this->aggregates, $this->clock);

        $series = $charts->buildMonitorSeries($m['child'], AggregatePeriod::Day, 0);
        self::assertSame(['2026-05-09'], $series['labels']);
        self::assertSame([90.0], $series['uptime_percent']);

        $overview = $charts->buildTenantOverview('main');
        self::assertSame('child', $overview[(string) $m['child']->getId()]['name']);
        self::assertCount(1, $overview);
    }
}
