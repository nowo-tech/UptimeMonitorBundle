<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Monitor\MonitorSettings;

use function in_array;
use function sprintf;

/**
 * Applies retries-before-down and upside-down mode (Uptime Kuma semantics).
 */
final class MonitorRetryService
{
    public function normalizeResult(Monitor $monitor, CheckResultDto $raw): CheckResultDto
    {
        $dto = $this->applyUpsideDown($monitor, $raw);

        return $this->applyRetries($monitor, $dto);
    }

    public function shouldUseRetryInterval(Monitor $monitor, CheckResultDto $raw): bool
    {
        if ($monitor->getRetries() === 0 || !$this->isFailure($raw->status)) {
            return false;
        }

        $settings = MonitorSettings::from($monitor);

        return $settings->getFailureStreak() < $monitor->getRetries();
    }

    public function scheduleAfterCheck(Monitor $monitor, DateTimeImmutable $from, CheckResultDto $raw): void
    {
        if ($this->shouldUseRetryInterval($monitor, $raw)) {
            $monitor->setNextCheckAt($from->modify(sprintf('+%d seconds', $monitor->getRetryIntervalSeconds())));

            return;
        }

        $monitor->scheduleNextCheck($from);
    }

    private function applyUpsideDown(Monitor $monitor, CheckResultDto $raw): CheckResultDto
    {
        $settings = MonitorSettings::from($monitor);
        if (!$settings->isUpsideDown()) {
            return $raw;
        }

        if ($this->isFailure($raw->status)) {
            return new CheckResultDto(CheckStatus::Up, $raw->latencyMs, $raw->statusCode, $raw->message, $raw->metadata);
        }

        return new CheckResultDto(CheckStatus::Down, $raw->latencyMs, $raw->statusCode, 'Upside-down: reachable', $raw->metadata);
    }

    private function applyRetries(Monitor $monitor, CheckResultDto $raw): CheckResultDto
    {
        $settings   = MonitorSettings::from($monitor);
        $maxRetries = $monitor->getRetries();

        if ($maxRetries === 0) {
            $settings->resetFailureStreak();

            return $raw;
        }

        if (!$this->isFailure($raw->status)) {
            $settings->resetFailureStreak();

            return $raw;
        }

        $streak = $settings->getFailureStreak() + 1;
        $settings->setFailureStreak($streak);

        if ($streak <= $maxRetries) {
            $previous = $monitor->getLastKnownStatus() ?? CheckStatus::Up;

            return new CheckResultDto(
                $previous,
                $raw->latencyMs,
                $raw->statusCode,
                sprintf('Retry %d/%d: %s', $streak, $maxRetries, $raw->message ?? 'check failed'),
                $raw->metadata,
            );
        }

        return $raw;
    }

    private function isFailure(CheckStatus $status): bool
    {
        return in_array($status, [CheckStatus::Down, CheckStatus::Degraded], true);
    }
}
