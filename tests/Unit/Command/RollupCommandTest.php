<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Command\RollupCommand;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Tests\Support\SingleManagerRegistry;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Since 1.29 (Beacon spec 239) the rollup rebuilds one monitor per unit of work, under the monitor row lock the checks
 * take, reading that monitor's results as plain rows.
 */
#[CoversClass(RollupCommand::class)]
final class RollupCommandTest extends TestCase
{
    private const NOW = '2026-03-10 12:30:00';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = SqliteEntityManagerFactory::create();
    }

    public function testExecuteRebuildsTheBucketsOfEveryMonitorFromItsResults(): void
    {
        $tenant = new Tenant('main', 'Main');
        $api    = new Monitor($tenant, 'API', MonitorType::Https, 'https://api.test');
        $web    = new Monitor($tenant, 'Web', MonitorType::Https, 'https://web.test');
        $this->em->persist($tenant);
        $this->em->persist($api);
        $this->em->persist($web);
        $this->em->flush();
        $at = new DateTimeImmutable('2026-03-08 10:15:00');
        $this->storeResult($api, CheckStatus::Up, 100, $at);
        $this->storeResult($api, CheckStatus::Down, 300, $at->modify('+5 minutes'));
        $this->storeResult($web, CheckStatus::Up, 50, $at);
        // Older than --days: not counted.
        $this->storeResult($api, CheckStatus::Up, 10, new DateTimeImmutable('2026-01-01 10:00:00'));
        // A bucket counted wrong before: overwritten with the totals of the results.
        $stale = new CheckAggregate($api, AggregatePeriod::Day, $at->setTime(0, 0));
        $stale->applyTotals(9, 9, 1);
        $this->em->persist($stale);
        $this->em->flush();
        $this->em->clear();

        $tester = $this->rollup(['periods' => ['hour', 'bad', 'day']], ['--days' => '7']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Processed 3 check(s) into 4 aggregate bucket(s).', $tester->getDisplay());
        $day = $this->bucket('API', AggregatePeriod::Day, $at->setTime(0, 0));
        self::assertSame([2, 1, 200], [$day->getChecksTotal(), $day->getChecksUp(), $day->getLatencyAvgMs()]);
        $hour = $this->bucket('Web', AggregatePeriod::Hour, $at->setTime(10, 0));
        self::assertSame([1, 1, 50], [$hour->getChecksTotal(), $hour->getChecksUp(), $hour->getLatencyAvgMs()]);
        self::assertSame(4, SqliteEntityManagerFactory::count($this->em, 'uptime_check_aggregate'));
    }

    public function testExecuteUsesTheDayPeriodWhenNoneIsConfigured(): void
    {
        $tenant = new Tenant('main', 'Main');
        $api    = new Monitor($tenant, 'API', MonitorType::Https, 'https://api.test');
        $this->em->persist($tenant);
        $this->em->persist($api);
        $this->em->flush();
        $this->storeResult($api, CheckStatus::Up, 100, new DateTimeImmutable('2026-03-09 08:00:00'));

        $tester = $this->rollup(['periods' => []], []);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Processed 1 check(s) into 1 aggregate bucket(s).', $tester->getDisplay());
    }

    public function testAMonitorDeletedBeforeItsUnitIsSkipped(): void
    {
        $tenant = new Tenant('main', 'Main');
        $api    = new Monitor($tenant, 'API', MonitorType::Https, 'https://api.test');
        $this->em->persist($tenant);
        $this->em->persist($api);
        $this->em->flush();
        $this->storeResult($api, CheckStatus::Up, 100, new DateTimeImmutable('2026-03-09 08:00:00'));
        // SQLite without enforced foreign keys keeps the orphan results: the rollup lists the id, the lock finds no row.
        $this->em->getConnection()->executeStatement('DELETE FROM uptime_monitor WHERE id = ?', [$api->getId()]);
        $this->em->clear();

        $tester = $this->rollup(['periods' => ['day']], []);

        self::assertStringContainsString('Processed 0 check(s) into 0 aggregate bucket(s).', $tester->getDisplay());
        self::assertSame(0, SqliteEntityManagerFactory::count($this->em, 'uptime_check_aggregate'));
    }

    /**
     * @param array<string, mixed> $aggregates
     * @param array<string, string> $input
     */
    private function rollup(array $aggregates, array $input): CommandTester
    {
        $command = new RollupCommand($this->em, new CheckAggregateRepository(new SingleManagerRegistry($this->em)), $aggregates, new MockClock(self::NOW));
        $tester  = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function storeResult(Monitor $monitor, CheckStatus $status, int $latency, DateTimeImmutable $at): void
    {
        $this->em->persist(new CheckResult($monitor, $status, $latency, null, null, null, $at));
        $this->em->flush();
    }

    private function bucket(string $monitorName, AggregatePeriod $period, DateTimeImmutable $start): CheckAggregate
    {
        $this->em->clear();
        $monitor = $this->em->getRepository(Monitor::class)->findOneBy(['name' => $monitorName]);
        self::assertInstanceOf(Monitor::class, $monitor);
        $bucket = $this->em->getRepository(CheckAggregate::class)->findOneBy(['monitor' => $monitor, 'period' => $period, 'periodStart' => $start]);
        self::assertInstanceOf(CheckAggregate::class, $bucket);

        return $bucket;
    }
}
