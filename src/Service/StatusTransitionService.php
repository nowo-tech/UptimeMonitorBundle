<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Monitor\MonitorSettings;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function in_array;
use function sprintf;

/**
 * Handles status changes, incidents, and notification cooldown.
 */
final class StatusTransitionService
{
    /**
     * @param array<string, mixed> $notificationsConfig
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IncidentRepository $incidentRepository,
        private readonly NotificationService $notificationService,
        #[Autowire('%nowo_uptime_monitor.notifications%')]
        private readonly array $notificationsConfig,
    ) {
    }

    public function handleAfterCheck(Monitor $monitor, CheckResult $result): void
    {
        $current  = $result->getStatus();
        $previous = $monitor->getLastKnownStatus();

        if ($previous === null) {
            $monitor->setLastKnownStatus($current);
            $this->updateIncidentState($monitor, $current, $result->getMessage());

            return;
        }

        if ($previous === $current) {
            $this->maybeResendDownNotification($monitor, $current, $result);

            return;
        }

        $monitor->setLastKnownStatus($current);
        $this->updateIncidentState($monitor, $current, $result->getMessage());
        $this->resetDownNotifyStreak($monitor, $current);
        $this->sendTransitionNotification($monitor, $current, $previous, $result->getMessage());
    }

    private function maybeResendDownNotification(Monitor $monitor, CheckStatus $current, CheckResult $result): void
    {
        if (!in_array($current, [CheckStatus::Down, CheckStatus::Degraded], true)) {
            return;
        }

        $threshold = MonitorSettings::from($monitor)->getResendNotificationAfterDownCount();
        if ($threshold === 0) {
            return;
        }

        $config                       = $monitor->getConfig();
        $streak                       = (int) ($config['down_notify_streak'] ?? 0) + 1;
        $config['down_notify_streak'] = $streak;
        $monitor->setConfig($config);

        if ($streak % $threshold !== 0 || !$this->shouldNotify($monitor)) {
            return;
        }

        $alert = new UptimeAlert(
            $monitor,
            UptimeAlert::TRANSITION_DOWN,
            $current,
            $current,
            $result->getMessage() ?? sprintf('Still down (%d consecutive checks)', $streak),
        );

        if ($this->notificationService->notify($alert) > 0) {
            $monitor->setLastAlertAt(new DateTimeImmutable());
        }
    }

    private function sendTransitionNotification(
        Monitor $monitor,
        CheckStatus $current,
        CheckStatus $previous,
        ?string $message,
    ): void {
        if (!$this->shouldNotify($monitor)) {
            return;
        }

        $transition = $current === CheckStatus::Up
            ? UptimeAlert::TRANSITION_UP
            : UptimeAlert::TRANSITION_DOWN;

        $alert = new UptimeAlert($monitor, $transition, $current, $previous, $message);

        if ($this->notificationService->notify($alert) > 0) {
            $monitor->setLastAlertAt(new DateTimeImmutable());
        }
    }

    private function resetDownNotifyStreak(Monitor $monitor, CheckStatus $current): void
    {
        if ($current === CheckStatus::Up) {
            $config = $monitor->getConfig();
            unset($config['down_notify_streak']);
            $monitor->setConfig($config);
        }
    }

    private function updateIncidentState(Monitor $monitor, CheckStatus $status, ?string $message): void
    {
        $open = $this->incidentRepository->findOpenForMonitor($monitor);

        if ($status === CheckStatus::Up) {
            if ($open !== null) {
                $open->resolve();
            }

            return;
        }

        if (in_array($status, [CheckStatus::Down, CheckStatus::Degraded], true) && $open === null) {
            $this->entityManager->persist(new Incident($monitor, $status, $message));
        }
    }

    private function shouldNotify(Monitor $monitor): bool
    {
        if (!($this->notificationsConfig['enabled'] ?? false)) {
            return false;
        }

        $cooldown = (int) ($this->notificationsConfig['cooldown_seconds'] ?? 300);
        $last     = $monitor->getLastAlertAt();

        if ($last === null) {
            return true;
        }

        return $last->modify(sprintf('+%d seconds', $cooldown)) <= new DateTimeImmutable();
    }
}
