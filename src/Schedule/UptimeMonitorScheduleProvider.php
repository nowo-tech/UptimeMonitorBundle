<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Schedule;

use Nowo\UptimeMonitorBundle\Message\RunDueChecksMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Registers the periodic due-check scan when scheduler mode is enabled.
 */
#[AsSchedule('uptime_monitor')]
final class UptimeMonitorScheduleProvider implements ScheduleProviderInterface
{
    /**
     * @param array<string, mixed> $schedulerConfig
     */
    public function __construct(
        #[Autowire('%nowo_uptime_monitor.scheduler%')]
        private readonly array $schedulerConfig,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();

        if (!($this->schedulerConfig['enabled'] ?? true)) {
            return $schedule;
        }

        if (($this->schedulerConfig['mode'] ?? 'scheduler') !== 'scheduler') {
            return $schedule;
        }

        $tick = (string) ($this->schedulerConfig['tick'] ?? '1 minute');

        $schedule->add(RecurringMessage::every($tick, new RunDueChecksMessage()));

        return $schedule;
    }
}
