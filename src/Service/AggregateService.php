<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckAggregate;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\AggregatePeriod;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Repository\CheckAggregateRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Maintains rollup buckets (hour/day/month/year) from individual check results.
 */
final class AggregateService
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
    }

    public function recordFromCheck(Monitor $monitor, CheckResultDto $dto): void
    {
        $periods = $this->aggregatesConfig['periods'] ?? ['hour', 'day', 'month', 'year'];
        $now     = new DateTimeImmutable();
        $isUp    = $dto->status === CheckStatus::Up;

        foreach ($periods as $periodValue) {
            $period = AggregatePeriod::tryFrom((string) $periodValue);
            if ($period === null) {
                continue;
            }

            $bucketStart = $this->bucketStart($now, $period);
            $aggregate   = $this->aggregateRepository->findOneForBucket($monitor, $period, $bucketStart);

            if ($aggregate === null) {
                $aggregate = new CheckAggregate($monitor, $period, $bucketStart);
                $this->entityManager->persist($aggregate);
            }

            $aggregate->recordCheck($isUp, $dto->latencyMs);
        }
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
