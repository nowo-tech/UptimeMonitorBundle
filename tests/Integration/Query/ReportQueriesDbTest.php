<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Integration\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Query\CheckAggregateQueryInterface;
use Nowo\UptimeMonitorBundle\Query\CheckAggregateTotals;
use Nowo\UptimeMonitorBundle\Query\CheckAggregateView;
use Nowo\UptimeMonitorBundle\Query\DoctrineCheckAggregateQuery;
use Nowo\UptimeMonitorBundle\Query\DoctrineIncidentQuery;
use Nowo\UptimeMonitorBundle\Query\IncidentDowntime;
use Nowo\UptimeMonitorBundle\Query\IncidentDowntimeSql;
use Nowo\UptimeMonitorBundle\Query\IncidentQueryInterface;
use Nowo\UptimeMonitorBundle\Query\IncidentView;
use Nowo\UptimeMonitorBundle\Query\MonitorRef;
use Nowo\UptimeMonitorBundle\Query\MonitorScope;
use Nowo\UptimeMonitorBundle\Query\MonitorScopeFilter;
use Nowo\UptimeMonitorBundle\Query\ReadRow;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use Nowo\UptimeMonitorBundle\Tests\Support\StatementLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function intdiv;
use function is_file;
use function microtime;
use function preg_replace;
use function sort;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The report read ports of 1.36 (rollups and incidents; Beacon's uptime report) over a real schema: SQLite (a file, so a
 * second connection sees the same database) by default, MySQL / MariaDB / PostgreSQL with UPTIME_TEST_DATABASE_URL.
 * The scope is the caller's slug list only, tenant slugs compare exactly, nothing is managed, and a report never waits
 * for (nor sees the uncommitted work of) a concurrent writer (REQ-RUNTIME-003).
 */
#[Group('db')]
#[CoversClass(DoctrineCheckAggregateQuery::class)]
#[CoversClass(DoctrineIncidentQuery::class)]
#[CoversClass(MonitorScope::class)]
#[CoversClass(MonitorScopeFilter::class)]
#[CoversClass(ReadRow::class)]
#[CoversClass(IncidentDowntimeSql::class)]
#[CoversClass(CheckAggregateTotals::class)]
#[CoversClass(CheckAggregateView::class)]
#[CoversClass(IncidentView::class)]
#[CoversClass(IncidentDowntime::class)]
final class ReportQueriesDbTest extends TestCase
{
    private ?string $file = null;
    private StatementLog $log;
    private EntityManagerInterface $em;
    private DoctrineCheckAggregateQuery $aggregates;
    private DoctrineIncidentQuery $incidents;
    private DateTimeImmutable $from;
    private DateTimeImmutable $to;

    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        $this->file       = SqliteEntityManagerFactory::onServer() ? null : (string) tempnam(sys_get_temp_dir(), 'uptime-report-');
        $this->log        = new StatementLog();
        $this->em         = SqliteEntityManagerFactory::create((new DbalConfiguration())->setMiddlewares([$this->log]), sqliteFile: $this->file);
        $this->aggregates = new DoctrineCheckAggregateQuery($this->em);
        $this->incidents  = new DoctrineIncidentQuery($this->em);
        $this->from       = new DateTimeImmutable('2026-11-01 00:00:00');
        $this->to         = new DateTimeImmutable('2026-11-08 00:00:00');

        $alpha = new Tenant('alpha', 'Alpha');
        $beta  = new Tenant('beta', 'Beta');
        $delta = new Tenant('delta', 'Delta');
        $empty = new Tenant('empty', 'Empty');
        foreach ([$alpha, $beta, $delta, $empty] as $tenant) {
            $this->em->persist($tenant);
        }
        $monitors = [
            'a-api'   => new Monitor($alpha, 'API', MonitorType::Https, 'https://api.test'),
            'a-web'   => new Monitor($alpha, 'Web', MonitorType::Https, 'https://web.test'),
            'a-group' => new Monitor($alpha, 'Group', MonitorType::Group, ''),
            'b-api'   => new Monitor($beta, 'B API', MonitorType::Https, 'https://b.test'),
            'd-api'   => new Monitor($delta, 'D API', MonitorType::Https, 'https://d.test'),
        ];
        foreach ($monitors as $monitor) {
            $this->em->persist($monitor);
        }

        // Day buckets: two in the window for a-api, one at the window start, one at the window end (excluded), one before.
        $this->bucket($monitors['a-api'], AggregatePeriod::Day, '2026-11-01 00:00:00', 100, 90, 200);
        $this->bucket($monitors['a-api'], AggregatePeriod::Day, '2026-11-02 00:00:00', 50, 50, 100);
        $this->bucket($monitors['a-api'], AggregatePeriod::Day, '2026-11-08 00:00:00', 10, 0, 999);
        $this->bucket($monitors['a-api'], AggregatePeriod::Day, '2026-10-31 00:00:00', 10, 0, 999);
        $this->bucket($monitors['a-api'], AggregatePeriod::Hour, '2026-11-02 05:00:00', 10, 10, 50);
        $this->bucket($monitors['a-web'], AggregatePeriod::Day, '2026-11-01 00:00:00', 20, 10, 300);
        $this->bucket($monitors['a-group'], AggregatePeriod::Day, '2026-11-03 00:00:00', 4, 4, 0);
        $this->bucket($monitors['b-api'], AggregatePeriod::Day, '2026-11-05 00:00:00', 30, 30, 40);
        $this->bucket($monitors['d-api'], AggregatePeriod::Day, '2026-11-05 00:00:00', 7, 7, 7);

        // Incidents of a-api: one inside, one across the window start, one open since inside, one before, one after.
        $this->incident($monitors['a-api'], '2026-11-02 10:00:00', '2026-11-02 10:30:00', 'GET https://user:s3cr3t@api.test/health failed');
        $this->incident($monitors['a-api'], '2026-10-31 23:00:00', '2026-11-01 01:00:00');
        $this->incident($monitors['a-api'], '2026-11-07 23:00:00', null, null, CheckStatus::Degraded);
        $this->incident($monitors['a-api'], '2026-10-20 00:00:00', '2026-10-21 00:00:00');
        $this->incident($monitors['a-api'], '2026-11-08 00:00:00', '2026-11-08 01:00:00');
        // Ended exactly at the window start: it overlaps (ended at `from` or later).
        $this->incident($monitors['a-web'], '2026-10-31 22:00:00', '2026-11-01 00:00:00');
        $this->incident($monitors['b-api'], '2026-11-04 00:00:00', '2026-11-04 00:00:10');
        $this->incident($monitors['d-api'], '2026-11-04 00:00:00', '2026-11-04 01:00:00');
        $this->em->flush();
        foreach ($monitors as $key => $monitor) {
            $this->id[$key] = (int) $monitor->getId();
        }
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testAnEmptyScopeOrWindowRunsNoQuery(): void
    {
        $this->log->statements = [];
        $alpha                 = MonitorScope::tenants(['alpha']);

        self::assertTrue((new MonitorScope())->isEmpty());
        self::assertTrue(MonitorScope::tenants(['alpha'], [])->isEmpty(), 'an empty id list matches nothing');
        self::assertTrue(MonitorScope::tenants(['alpha'], [0, -3])->isEmpty(), 'invalid ids are dropped');
        self::assertTrue(MonitorScope::tenants(['ALPHA', ''])->isEmpty(), 'invalid slugs are dropped');
        foreach ([new MonitorScope(), MonitorScope::tenants(['alpha'], [])] as $empty) {
            self::assertSame([], $this->aggregates->totalsByMonitor($empty, $this->from, $this->to));
            self::assertSame([], $this->aggregates->list($empty, $this->from, $this->to));
            self::assertSame(0, $this->aggregates->count($empty, $this->from, $this->to));
            self::assertSame([], $this->incidents->list($empty, $this->from, $this->to));
            self::assertSame(0, $this->incidents->count($empty, $this->from, $this->to));
            self::assertSame([], $this->incidents->downtimeByMonitor($empty, $this->from, $this->to, $this->to));
        }
        self::assertSame([], $this->aggregates->totalsByMonitor($alpha, $this->to, $this->from), 'an empty window');
        self::assertSame([], $this->aggregates->list($alpha, $this->to, $this->to));
        self::assertSame([], $this->incidents->list($alpha, $this->to, $this->from));
        self::assertSame(0, $this->incidents->count($alpha, $this->to, $this->from));
        self::assertSame([], $this->incidents->downtimeByMonitor($alpha, $this->to, $this->from, $this->to));
        self::assertSame([], $this->log->statements, 'no statement ran');
    }

    public function testTotalsSumTheWindowsBucketsPerMonitor(): void
    {
        $totals = $this->aggregates->totalsByMonitor(MonitorScope::tenants(['alpha', 'beta']), $this->from, $this->to);

        self::assertSame([$this->id['a-api'], $this->id['a-web'], $this->id['a-group'], $this->id['b-api']], array_keys($totals));
        $api = $totals[$this->id['a-api']];
        self::assertSame(['alpha', 2, 150, 140, 10, 100 * 200 + 50 * 100], [$api->tenantSlug, $api->buckets, $api->checksTotal, $api->checksUp, $api->checksDown, $api->latencyWeightedMs]);
        self::assertEqualsWithDelta(140 / 150, $api->uptimeRatio(), 0.0001);
        self::assertSame(intdiv(25000, 150), $api->latencyAvgMs());
        self::assertSame('beta', $totals[$this->id['b-api']]->tenantSlug);

        $hours = $this->aggregates->totalsByMonitor(MonitorScope::tenants(['alpha']), $this->from, $this->to, AggregatePeriod::Hour);
        self::assertSame([$this->id['a-api']], array_keys($hours));
        self::assertSame(10, $hours[$this->id['a-api']]->checksUp);

        $none = new CheckAggregateTotals(1, 'alpha', 0, 0, 0, 0, 0);
        self::assertNull($none->uptimeRatio());
        self::assertNull($none->latencyAvgMs());
    }

    public function testTheWeightedLatencySumPastInt4IsExact(): void
    {
        // latency x checks = 3,153,600,000 > 2^31 (a year of 30 s checks at 3 s): int4 arithmetic would overflow on PostgreSQL.
        $web = $this->em->find(Monitor::class, $this->id['a-web']);
        self::assertInstanceOf(Monitor::class, $web);
        $this->bucket($web, AggregatePeriod::Year, '2024-01-01 00:00:00', 1_051_200, 1_051_200, 3000);
        $this->bucket($web, AggregatePeriod::Year, '2025-01-01 00:00:00', 1_051_200, 1_051_200, 1000);
        $this->em->flush();
        $this->em->clear();
        $from  = new DateTimeImmutable('2024-01-01 00:00:00');
        $to    = new DateTimeImmutable('2026-01-01 00:00:00');
        $scope = MonitorScope::tenants(['alpha']);

        $totals = $this->aggregates->totalsByMonitor($scope, $from, $to, AggregatePeriod::Year);

        self::assertSame([$this->id['a-web']], array_keys($totals));
        $web = $totals[$this->id['a-web']];
        self::assertSame(2, $web->buckets);
        self::assertSame(2_102_400, $web->checksTotal);
        self::assertSame(1_051_200 * 3000 + 1_051_200 * 1000, $web->latencyWeightedMs);
        self::assertSame(2000, $web->latencyAvgMs());
        self::assertSame(2, $this->aggregates->count($scope, $from, $to, AggregatePeriod::Year));
        self::assertCount(2, $this->aggregates->list($scope, $from, $to, AggregatePeriod::Year));
    }

    public function testBucketsPageOldestFirstAndCountTheirTotal(): void
    {
        $scope = MonitorScope::tenants(['alpha', 'beta']);
        $all   = $this->aggregates->list($scope, $this->from, $this->to, limit: 50);

        self::assertSame(5, $this->aggregates->count($scope, $this->from, $this->to));
        self::assertSame(
            [['2026-11-01', $this->id['a-api']], ['2026-11-01', $this->id['a-web']], ['2026-11-02', $this->id['a-api']], ['2026-11-03', $this->id['a-group']], ['2026-11-05', $this->id['b-api']]],
            array_map(static fn (CheckAggregateView $v): array => [$v->periodStart->format('Y-m-d'), $v->monitorId], $all),
        );
        $first = $all[0];
        self::assertSame(['alpha', AggregatePeriod::Day, 100, 90, 10, 200], [$first->tenantSlug, $first->period, $first->checksTotal, $first->checksUp, $first->checksDown, $first->latencyAvgMs]);
        self::assertEqualsWithDelta(0.9, $first->uptimeRatio, 0.0001);
        self::assertGreaterThan(0, $first->id);

        $pages = [];
        for ($offset = 0; $offset < 5; $offset += 2) {
            foreach ($this->aggregates->list($scope, $this->from, $this->to, limit: 2, offset: $offset) as $view) {
                $pages[] = $view->id;
            }
        }
        self::assertSame(array_map(static fn (CheckAggregateView $v): int => $v->id, $all), $pages, 'pages are stable and disjoint');
        self::assertCount(1, $this->aggregates->list($scope, $this->from, $this->to, limit: 0), 'the page size is clamped to 1');
        self::assertCount(5, $this->aggregates->list($scope, $this->from, $this->to, limit: CheckAggregateQueryInterface::MAX_LIMIT + 1, offset: -4), 'and to the max page; offset to 0');
    }

    public function testGrantsAreBoundToTheirTenantAndIdsOnlyNarrow(): void
    {
        $grant = new MonitorScope([], [new MonitorRef('beta', $this->id['b-api']), new MonitorRef('beta', $this->id['a-api'])]);

        self::assertSame([$this->id['b-api']], array_keys($this->aggregates->totalsByMonitor($grant, $this->from, $this->to)), 'a-api granted under the wrong tenant is not read');
        self::assertSame([$this->id['b-api']], array_keys($this->incidents->downtimeByMonitor($grant, $this->from, $this->to, $this->to)));
        self::assertSame(1, $this->incidents->count($grant, $this->from, $this->to));

        $narrow = MonitorScope::tenants(['alpha'], [$this->id['a-web'], $this->id['b-api'], $this->id['a-web']]);
        self::assertSame([$this->id['a-web'], $this->id['b-api']], $narrow->monitorIds, 'duplicates dropped');
        self::assertSame([$this->id['a-web']], array_keys($this->aggregates->totalsByMonitor($narrow, $this->from, $this->to)));
        self::assertSame([$this->id['a-web']], array_map(static fn (IncidentView $v): int => $v->monitorId, $this->incidents->list($narrow, $this->from, $this->to)));
        self::assertTrue($narrow->covers('alpha', $this->id['a-web']));
        self::assertFalse($narrow->covers('alpha', $this->id['a-api']), 'outside the id filter');
        self::assertFalse($narrow->covers('beta', $this->id['a-web']));
    }

    public function testALookAlikeTenantIsNeverRead(): void
    {
        // A tenant row whose slug differs from a named one only by case (written outside the bundle): a case-insensitive
        // collation matches it in SQL, the PHP re-check drops it (house LQ rule).
        $connection = $this->em->getConnection();
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            // SQLite compares exactly by default: rebuild the slug column NOCASE so SQL matches the look-alike, like MySQL's ci collations.
            $ddl = (string) $connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'uptime_tenant'");
            $new = (string) preg_replace('/(["`]?slug["`]?\s+VARCHAR\(\d+\)\s+NOT NULL)/i', '$1 COLLATE NOCASE', str_replace('uptime_tenant', 'uptime_tenant_nc', $ddl), 1, $count);
            self::assertSame(1, $count, 'slug column found in: ' . $ddl);
            $connection->executeStatement('PRAGMA foreign_keys = OFF');
            $connection->executeStatement($new);
            $connection->executeStatement('INSERT INTO uptime_tenant_nc SELECT * FROM uptime_tenant');
            $connection->executeStatement('DROP TABLE uptime_tenant');
            $connection->executeStatement('ALTER TABLE uptime_tenant_nc RENAME TO uptime_tenant');
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }
        $connection->executeStatement("UPDATE uptime_tenant SET slug = 'DELTA' WHERE slug = 'delta'");
        $scopes = [MonitorScope::tenants(['delta']), new MonitorScope([], [new MonitorRef('delta', $this->id['d-api'])])];

        foreach ($scopes as $scope) {
            self::assertSame([], $this->aggregates->totalsByMonitor($scope, $this->from, $this->to));
            self::assertSame([], $this->aggregates->list($scope, $this->from, $this->to));
            self::assertSame(0, $this->aggregates->count($scope, $this->from, $this->to));
            self::assertSame([], $this->incidents->list($scope, $this->from, $this->to));
            self::assertSame(0, $this->incidents->count($scope, $this->from, $this->to));
            self::assertSame([], $this->incidents->downtimeByMonitor($scope, $this->from, $this->to, $this->to));
        }
        self::assertSame([$this->id['b-api']], array_keys($this->incidents->downtimeByMonitor(MonitorScope::tenants(['delta', 'beta']), $this->from, $this->to, $this->to)));
    }

    public function testIncidentsOverlappingTheWindowPageNewestFirst(): void
    {
        $scope = MonitorScope::tenants(['alpha']);
        $list  = $this->incidents->list($scope, $this->from, $this->to);

        self::assertSame(
            ['2026-11-07 23:00:00', '2026-11-02 10:00:00', '2026-10-31 23:00:00', '2026-10-31 22:00:00'],
            array_map(static fn (IncidentView $v): string => $v->startedAt->format('Y-m-d H:i:s'), $list),
        );
        self::assertSame(4, $this->incidents->count($scope, $this->from, $this->to));
        self::assertTrue($list[0]->isOpen());
        self::assertSame(CheckStatus::Degraded, $list[0]->triggerStatus);
        self::assertNull($list[0]->message);
        self::assertFalse($list[1]->isOpen());
        self::assertSame('2026-11-02 10:30:00', $list[1]->endedAt?->format('Y-m-d H:i:s'));
        self::assertSame(['alpha', $this->id['a-api'], CheckStatus::Down], [$list[1]->tenantSlug, $list[1]->monitorId, $list[1]->triggerStatus]);
        self::assertStringNotContainsString('s3cr3t', (string) $list[1]->message, 'URLs in the message are masked');
        self::assertStringContainsString('api.test/health', (string) $list[1]->message);

        self::assertSame(1, $this->incidents->count($scope, $this->from, $this->to, open: true));
        self::assertSame(3, $this->incidents->count($scope, $this->from, $this->to, open: false));
        self::assertCount(1, $this->incidents->list($scope, $this->from, $this->to, open: true));
        self::assertSame([$list[1]->id, $list[2]->id], array_map(static fn (IncidentView $v): int => $v->id, $this->incidents->list($scope, $this->from, $this->to, limit: 2, offset: 1)));
        self::assertCount(1, $this->incidents->list($scope, $this->from, $this->to, limit: -1, offset: -1), 'clamped page');
        self::assertCount(4, $this->incidents->list($scope, $this->from, $this->to, limit: IncidentQueryInterface::MAX_LIMIT + 1));
    }

    public function testDowntimeIsTheIncidentsTimeInsideTheWindow(): void
    {
        $now      = new DateTimeImmutable('2026-11-07 23:45:00');
        $downtime = $this->incidents->downtimeByMonitor(MonitorScope::tenants(['alpha', 'beta', 'empty']), $this->from, $this->to, $now);

        self::assertSame([$this->id['a-api'], $this->id['a-web'], $this->id['b-api']], $this->sortedKeys($downtime));
        $api = $downtime[$this->id['a-api']];
        // 30 min inside + 1 h after the window start + the open one until now (45 min).
        self::assertSame(['alpha', 3, 1800 + 3600 + 2700], [$api->tenantSlug, $api->incidents, $api->seconds]);
        self::assertSame([1, 0], [$downtime[$this->id['a-web']]->incidents, $downtime[$this->id['a-web']]->seconds], 'ended at the window start: counted, no time inside');
        self::assertSame(10, $downtime[$this->id['b-api']]->seconds);

        $later = $this->incidents->downtimeByMonitor(MonitorScope::tenants(['alpha']), $this->from, $this->to, new DateTimeImmutable('2026-11-09 00:00:00'));
        self::assertSame(1800 + 3600 + 3600, $later[$this->id['a-api']]->seconds, 'an open incident stops at the window end');
        $before = $this->incidents->downtimeByMonitor(MonitorScope::tenants(['alpha']), $this->from, $this->to, new DateTimeImmutable('2026-11-07 22:00:00'));
        self::assertSame(1800 + 3600, $before[$this->id['a-api']]->seconds, 'never negative when "now" is before the start');

        self::assertSame([], $this->incidents->downtimeByMonitor(MonitorScope::tenants(['empty']), $this->from, $this->to, $now), 'a tenant without monitors');
    }

    public function testNothingStaysManaged(): void
    {
        $scope = MonitorScope::tenants(['alpha', 'beta']);
        $this->aggregates->list($scope, $this->from, $this->to);
        $this->aggregates->totalsByMonitor($scope, $this->from, $this->to);
        $this->incidents->list($scope, $this->from, $this->to);
        $this->incidents->downtimeByMonitor($scope, $this->from, $this->to, $this->to);

        self::assertSame(0, $this->em->getUnitOfWork()->size());
    }

    public function testAReportNeverWaitsForNorSeesAConcurrentWriter(): void
    {
        // REQ-RUNTIME-003: another connection rolls up and resolves inside a transaction it has not committed yet (it
        // holds the row locks); the report reads the committed state at once, without a lock wait or an error.
        $writer = DriverManager::getConnection(SqliteEntityManagerFactory::params($this->file));
        $writer->beginTransaction();
        $writer->executeStatement('UPDATE uptime_check_aggregate SET checks_up = 0, checks_down = checks_total');
        $writer->executeStatement('UPDATE uptime_incident SET ended_at = started_at WHERE ended_at IS NULL');
        $writer->insert('uptime_check_aggregate', ['monitor_id' => $this->id['a-web'], 'period' => 'day', 'period_start' => '2026-11-06 00:00:00', 'checks_total' => 1, 'checks_up' => 1, 'checks_down' => 0, 'uptime_ratio' => 1.0, 'latency_avg_ms' => 1]);
        $scope = MonitorScope::tenants(['alpha']);

        try {
            $started = microtime(true);
            $totals  = $this->aggregates->totalsByMonitor($scope, $this->from, $this->to);
            $count   = $this->aggregates->count($scope, $this->from, $this->to);
            $open    = $this->incidents->count($scope, $this->from, $this->to, open: true);
            $seconds = $this->incidents->downtimeByMonitor($scope, $this->from, $this->to, new DateTimeImmutable('2026-11-07 23:45:00'))[$this->id['a-api']]->seconds;
            $elapsed = microtime(true) - $started;
        } finally {
            $writer->rollBack();
            $writer->close();
        }

        self::assertSame(140, $totals[$this->id['a-api']]->checksUp, 'the committed rollup');
        self::assertSame(4, $count, 'the uncommitted bucket is not counted');
        self::assertSame(1, $open, 'the incident the writer resolves is still open for the report');
        self::assertSame(1800 + 3600 + 2700, $seconds);
        self::assertLessThan(5.0, $elapsed, 'no lock wait');
    }

    public function testTheWriterCommitIsSeenByTheNextRead(): void
    {
        $writer = $this->otherConnection();
        $scope  = MonitorScope::tenants(['beta']);
        self::assertSame(30, $this->aggregates->totalsByMonitor($scope, $this->from, $this->to)[$this->id['b-api']]->checksUp);

        $writer->executeStatement('UPDATE uptime_check_aggregate SET checks_up = 29, checks_down = 1 WHERE monitor_id = ?', [$this->id['b-api']]);
        $writer->close();

        self::assertSame(29, $this->aggregates->totalsByMonitor($scope, $this->from, $this->to)[$this->id['b-api']]->checksUp, 'no memo across calls');
    }

    private function otherConnection(): Connection
    {
        return DriverManager::getConnection(SqliteEntityManagerFactory::params($this->file));
    }

    /**
     * @param array<int, mixed> $map
     *
     * @return list<int>
     */
    private function sortedKeys(array $map): array
    {
        $keys = array_keys($map);
        sort($keys);

        return $keys;
    }

    private function bucket(Monitor $monitor, AggregatePeriod $period, string $start, int $total, int $up, int $latency): void
    {
        $bucket = new CheckAggregate($monitor, $period, new DateTimeImmutable($start));
        $bucket->applyTotals($total, $up, $latency);
        $this->em->persist($bucket);
    }

    private function incident(Monitor $monitor, string $started, ?string $ended, ?string $message = null, CheckStatus $status = CheckStatus::Down): void
    {
        $incident = new Incident($monitor, $status, $message, new DateTimeImmutable($started));
        if ($ended !== null) {
            $incident->resolve(new DateTimeImmutable($ended));
        }
        $this->em->persist($incident);
    }
}
