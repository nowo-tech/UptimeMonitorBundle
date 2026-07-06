<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Command;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function count;
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
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CheckAggregateRepository $aggregateRepository,
        #[Autowire('%nowo_uptime_monitor.aggregates%')]
        private readonly array $aggregatesConfig,
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
        $from = new DateTimeImmutable(sprintf('-%d days', $days));

        /** @var list<CheckResult> $results */
        $results = $this->entityManager->createQueryBuilder()
            ->select('c', 'm')
            ->from(CheckResult::class, 'c')
            ->innerJoin('c.monitor', 'm')
            ->andWhere('c.checkedAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('c.checkedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $periods = $this->resolvePeriods();
        $buckets = [];

        foreach ($results as $result) {
            $monitor = $result->getMonitor();
            $isUp    = $result->getStatus() === CheckStatus::Up;

            foreach ($periods as $period) {
                $start = $this->bucketStart($result->getCheckedAt(), $period);
                $key   = sprintf('%d-%s-%s', $monitor->getId(), $period->value, $start->format('c'));

                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'monitor'     => $monitor,
                        'period'      => $period,
                        'start'       => $start,
                        'up'          => 0,
                        'down'        => 0,
                        'latency_sum' => 0,
                        'total'       => 0,
                    ];
                }

                ++$buckets[$key]['total'];
                if ($isUp) {
                    ++$buckets[$key]['up'];
                } else {
                    ++$buckets[$key]['down'];
                }
                $buckets[$key]['latency_sum'] += $result->getLatencyMs();
            }
        }

        foreach ($buckets as $bucket) {
            $aggregate = $this->aggregateRepository->findOneForBucket(
                $bucket['monitor'],
                $bucket['period'],
                $bucket['start'],
            );

            if ($aggregate === null) {
                $aggregate = new CheckAggregate($bucket['monitor'], $bucket['period'], $bucket['start']);
                $this->entityManager->persist($aggregate);
            }

            $total      = (int) $bucket['total'];
            $avgLatency = (int) round($bucket['latency_sum'] / $total);

            $aggregate->applyTotals($total, $bucket['up'], $avgLatency);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Processed %d check(s) into %d aggregate bucket(s).', count($results), count($buckets)));

        return Command::SUCCESS;
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
