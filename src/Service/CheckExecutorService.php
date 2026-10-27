<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

use function sprintf;

/**
 * Runs monitor checks and persists detail results.
 */
final readonly class CheckExecutorService
{
    /**
     * @param iterable<CheckRunnerInterface> $checkRunners
     */
    public function __construct(
        #[AutowireIterator('nowo.uptime_monitor.check_runner')]
        private iterable $checkRunners,
        private EntityManagerInterface $entityManager,
        private AggregateService $aggregateService,
        private StatusTransitionService $statusTransitionService,
        private DashboardSyncDispatcher $dashboardSyncDispatcher,
        private CheckLatencyNormalizer $latencyNormalizer,
        private MonitorRetryService $monitorRetryService,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Runs the monitor check, stores the result, aggregates, incidents and notifications, schedules the next check,
     * notifies the dashboards and refreshes the parent group.
     */
    public function execute(Monitor $monitor): CheckResult
    {
        // Remote data (DNS records, transport errors) is scrubbed to valid, bounded UTF-8 before anything stores it.
        $raw = $this->runCheck($monitor)->sanitized();
        $dto = $this->latencyNormalizer->normalizeDto(
            $monitor,
            $this->monitorRetryService->normalizeResult($monitor, $raw),
        );
        $now = $this->clock->now();

        $result = new CheckResult(
            $monitor,
            $dto->status,
            $dto->latencyMs,
            $dto->statusCode,
            $dto->message,
            $dto->metadata,
            $now,
        );

        $this->entityManager->persist($result);
        $this->monitorRetryService->scheduleAfterCheck($monitor, $now, $raw);
        $this->aggregateService->recordFromCheck($monitor, $dto);
        $this->statusTransitionService->handleAfterCheck($monitor, $result);
        $this->entityManager->flush();
        $this->dashboardSyncDispatcher->dispatchAfterCheck($monitor, $result);
        $this->refreshParentGroupAfterChildCheck($monitor);

        return $result;
    }

    private function refreshParentGroupAfterChildCheck(Monitor $monitor): void
    {
        $parent = $monitor->getParent();
        if (!$parent instanceof Monitor || $parent->getType() !== MonitorType::Group || $parent->isPaused()) {
            return;
        }

        $this->execute($parent);
    }

    private function runCheck(Monitor $monitor): CheckResultDto
    {
        foreach ($this->checkRunners as $runner) {
            if ($runner->supports($monitor)) {
                try {
                    return $runner->run($monitor);
                } catch (Throwable $e) {
                    // A broken check is recorded (and the monitor rescheduled) instead of aborting the shared scan.
                    return new CheckResultDto(CheckStatus::Unknown, 0, null, sprintf('Check failed (%s)', $e::class));
                }
            }
        }

        return new CheckResultDto(
            CheckStatus::Unknown,
            0,
            null,
            sprintf('No check runner registered for type "%s"', $monitor->getType()->value),
        );
    }
}
