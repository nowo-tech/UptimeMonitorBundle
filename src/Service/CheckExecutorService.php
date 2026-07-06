<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Check\CheckRunnerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function sprintf;

/**
 * Runs monitor checks and persists detail results.
 */
final class CheckExecutorService
{
    /**
     * @param iterable<CheckRunnerInterface> $checkRunners
     */
    public function __construct(
        #[AutowireIterator('nowo.uptime_monitor.check_runner')]
        private readonly iterable $checkRunners,
        private readonly EntityManagerInterface $entityManager,
        private readonly AggregateService $aggregateService,
        private readonly StatusTransitionService $statusTransitionService,
        private readonly DashboardSyncDispatcher $dashboardSyncDispatcher,
        private readonly CheckLatencyNormalizer $latencyNormalizer,
        private readonly MonitorRetryService $monitorRetryService,
    ) {
    }

    public function execute(Monitor $monitor): CheckResult
    {
        $raw = $this->runCheck($monitor);
        $dto = $this->latencyNormalizer->normalizeDto(
            $monitor,
            $this->monitorRetryService->normalizeResult($monitor, $raw),
        );
        $now = new DateTimeImmutable();

        $result = new CheckResult(
            $monitor,
            $dto->status,
            $dto->latencyMs,
            $dto->statusCode,
            $dto->message,
            $dto->metadata,
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
        if ($parent === null || $parent->getType() !== MonitorType::Group || $parent->isPaused()) {
            return;
        }

        $this->execute($parent);
    }

    private function runCheck(Monitor $monitor): CheckResultDto
    {
        foreach ($this->checkRunners as $runner) {
            if ($runner->supports($monitor)) {
                return $runner->run($monitor);
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
