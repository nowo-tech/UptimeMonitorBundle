<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Command;

use DateTimeImmutable;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeRowLock;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use SortDirection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_map;
use function count;
use function round;
use function sort;
use function sprintf;

/**
 * Rebuilds aggregate buckets from detail check results (repair / backfill).
 */
#[AsCommand(
    name: 'nowo:uptime:rollup',
    description: 'Rebuild uptime aggregates from check detail records',
)]
final class RollupCommand extends Command
{
    /**
     * @param array<string, mixed> $aggregatesConfig
     * @param UptimeTransactionRunner|null $transactionRunner The bundle's runner (since 1.29; null = one on $entityManager)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CheckAggregateRepository $aggregateRepository,
        #[Autowire('%nowo_uptime_monitor.aggregates%')]
        private readonly array $aggregatesConfig,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly ?UptimeTransactionRunner $transactionRunner = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Only process detail from the last N days', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $from = $this->clock->now()->modify(sprintf('-%d days', $days));

        $periods = $this->resolvePeriods();
        $runner  = $this->transactionRunner ?? new UptimeTransactionRunner($this->entityManager);
        $checks  = 0;
        $buckets = 0;

        // Since 1.29 (Beacon spec 239): one unit of work per monitor, which locks the monitor row first (the lock the
        // checks take), then reads that monitor's results and writes its buckets. A check stored meanwhile is either
        // counted in the rebuilt totals or recorded after them, never overwritten; a deadlock or lock wait replays the
        // monitor. The results are read per monitor as plain rows, not every result of every monitor as entities.
        foreach ($this->monitorIds($from) as $monitorId) {
            [$counted, $written] = $runner->runRetrying(fn (): array => $this->rebuild($monitorId, $from, $periods));
            $checks += $counted;
            $buckets += $written;
            // The bundle's own manager: what this monitor loaded is released before the next one.
            $this->entityManager->clear();
        }

        $io->success(sprintf('Processed %d check(s) into %d aggregate bucket(s).', $checks, $buckets));

        return Command::SUCCESS;
    }

    /**
     * Ids of the monitors with results since `$from`.
     *
     * @return list<int>
     */
    private function monitorIds(DateTimeImmutable $from): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(c.monitor)')
            ->distinct()
            ->from(CheckResult::class, 'c')
            ->andWhere('c.checkedAt >= :from')
            ->setParameter('from', $from)
            ->getQuery()
            ->getSingleColumnResult();

        $ids = array_map(static fn (int|string $id): int => (int) $id, $ids);
        sort($ids);

        return $ids;
    }

    /**
     * The unit of work of one monitor: its row locked, its results since `$from` read, its buckets written.
     *
     * @param list<AggregatePeriod> $periods
     *
     * @return array{0: int, 1: int} Results counted, buckets written
     */
    private function rebuild(int $monitorId, DateTimeImmutable $from, array $periods): array
    {
        $monitor = UptimeRowLock::lock($this->entityManager, Monitor::class, $monitorId);
        if (!$monitor instanceof Monitor) {
            return [0, 0];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.status', 'c.latencyMs', 'c.checkedAt')
            ->from(CheckResult::class, 'c')
            ->andWhere('c.monitor = :monitor')
            ->andWhere('c.checkedAt >= :from')
            ->setParameter('monitor', $monitor)
            ->setParameter('from', $from)
            ->orderBy('c.checkedAt', SortDirection::Ascending)
            ->getQuery()
            ->toIterable([], AbstractQuery::HYDRATE_ARRAY);

        $buckets = [];
        $counted = 0;
        foreach ($rows as $row) {
            /* @var array{status: CheckStatus|string, latencyMs: int|string, checkedAt: DateTimeImmutable} $row */
            ++$counted;
            $isUp = ($row['status'] instanceof CheckStatus ? $row['status'] : CheckStatus::from($row['status'])) === CheckStatus::Up;

            foreach ($periods as $period) {
                $start = $this->bucketStart($row['checkedAt'], $period);
                $key   = sprintf('%s-%s', $period->value, $start->format('c'));

                $buckets[$key] ??= [
                    'period'      => $period,
                    'start'       => $start,
                    'up'          => 0,
                    'latency_sum' => 0,
                    'total'       => 0,
                ];

                ++$buckets[$key]['total'];
                if ($isUp) {
                    ++$buckets[$key]['up'];
                }
                $buckets[$key]['latency_sum'] += (int) $row['latencyMs'];
            }
        }

        foreach ($buckets as $bucket) {
            $aggregate = $this->aggregateRepository->findOneForBucket($monitor, $bucket['period'], $bucket['start']);

            if (!$aggregate instanceof CheckAggregate) {
                $aggregate = new CheckAggregate($monitor, $bucket['period'], $bucket['start']);
                $this->entityManager->persist($aggregate);
            }

            $total = $bucket['total'];
            $aggregate->applyTotals($total, $bucket['up'], (int) round($bucket['latency_sum'] / $total));
        }

        return [$counted, count($buckets)];
    }

    /**
     * @return list<AggregatePeriod>
     */
    private function resolvePeriods(): array
    {
        $periods = [];
        foreach ($this->aggregatesConfig['periods'] ?? ['day'] as $value) {
            $period = AggregatePeriod::tryFrom((string) $value);
            if ($period !== null) {
                $periods[] = $period;
            }
        }

        return $periods !== [] ? $periods : [AggregatePeriod::Day];
    }

    private function bucketStart(DateTimeImmutable $at, AggregatePeriod $period): DateTimeImmutable
    {
        return match ($period) {
            AggregatePeriod::Hour  => $at->setTime((int) $at->format('H'), 0, 0),
            AggregatePeriod::Day   => $at->setTime(0, 0, 0),
            AggregatePeriod::Month => $at->modify('first day of this month')->setTime(0, 0, 0),
            AggregatePeriod::Year  => $at->modify('first day of january')->setTime(0, 0, 0),
        };
    }
}
