<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Event\MonitorRecoveredEvent;
use Nowo\UptimeMonitorBundle\Event\MonitorWentDownEvent;
use Nowo\UptimeMonitorBundle\Monitor\MonitorSettings;
use Nowo\UptimeMonitorBundle\Notification\UptimeAlert;
use Nowo\UptimeMonitorBundle\Repository\IncidentRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function in_array;
use function sprintf;

/**
 * Handles status changes, incidents, and notification cooldown.
 */
final readonly class StatusTransitionService
{
    /**
     * @param array<string, mixed> $notificationsConfig
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IncidentRepository $incidentRepository,
        private NotificationService $notificationService,
        #[Autowire('%nowo_uptime_monitor.notifications%')]
        private array $notificationsConfig,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Updates the known status, opens or resolves incidents and sends transition or resend notifications. The incident
     * events are dispatched right away (before any flush); {@see CheckExecutorService} uses
     * {@see self::handleAfterCheckDeferred()} instead, since 1.25.
     */
    public function handleAfterCheck(Monitor $monitor, CheckResult $result): void
    {
        $this->transition($monitor, $result, function (object $event): void {
            $this->eventDispatcher?->dispatch($event);
        });
    }

    /**
     * Same as {@see self::handleAfterCheck()}, but returns the incident events instead of dispatching them (since
     * 1.25): the caller flushes, then hands them to {@see self::dispatchCommitted()}, so a listener sees the persisted
     * incident (with its id) and a failed flush dispatches nothing.
     *
     * @return list<MonitorRecoveredEvent|MonitorWentDownEvent>
     */
    public function handleAfterCheckDeferred(Monitor $monitor, CheckResult $result): array
    {
        $events = [];
        $this->transition($monitor, $result, static function (MonitorWentDownEvent|MonitorRecoveredEvent $event) use (&$events): void {
            $events[] = $event;
        });

        return $events;
    }

    /**
     * Dispatches the incident events of {@see self::handleAfterCheckDeferred()} once the check is committed (since
     * 1.25), then flushes what the listeners persisted (before 1.25 they shared the check's flush). Nothing to do
     * without events.
     *
     * @param list<MonitorRecoveredEvent|MonitorWentDownEvent> $events
     */
    public function dispatchCommitted(array $events): void
    {
        if ($events === []) {
            return;
        }

        foreach ($events as $event) {
            $this->eventDispatcher?->dispatch($event);
        }

        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            $this->entityManager->flush();
        }
    }

    /**
     * @param callable(MonitorRecoveredEvent|MonitorWentDownEvent): void $emit Receives the incident events in order
     */
    private function transition(Monitor $monitor, CheckResult $result, callable $emit): void
    {
        $current  = $result->getStatus();
        $previous = $monitor->getLastKnownStatus();

        if (!$previous instanceof CheckStatus) {
            $monitor->setLastKnownStatus($current);
            $this->updateIncidentState($monitor, $current, $result->getMessage(), $emit);

            return;
        }

        if ($previous === $current) {
            $this->maybeResendDownNotification($monitor, $current, $result);

            return;
        }

        $monitor->setLastKnownStatus($current);
        $this->updateIncidentState($monitor, $current, $result->getMessage(), $emit);
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
            $monitor->setLastAlertAt($this->clock->now());
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
            $monitor->setLastAlertAt($this->clock->now());
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

    /**
     * @param callable(MonitorRecoveredEvent|MonitorWentDownEvent): void $emit
     */
    private function updateIncidentState(Monitor $monitor, CheckStatus $status, ?string $message, callable $emit): void
    {
        $open = $this->incidentRepository->findOpenForMonitor($monitor);

        if ($status === CheckStatus::Up) {
            if ($open instanceof Incident) {
                $open->resolve($this->clock->now());
                $emit(new MonitorRecoveredEvent($monitor, $open));
            }

            return;
        }

        if (in_array($status, [CheckStatus::Down, CheckStatus::Degraded], true) && !$open instanceof Incident) {
            $incident = new Incident($monitor, $status, $message, $this->clock->now());
            $this->entityManager->persist($incident);
            $emit(new MonitorWentDownEvent($monitor, $incident, $message));
        }
    }

    private function shouldNotify(Monitor $monitor): bool
    {
        if (!($this->notificationsConfig['enabled'] ?? false)) {
            return false;
        }

        $cooldown = (int) ($this->notificationsConfig['cooldown_seconds'] ?? 300);
        $last     = $monitor->getLastAlertAt();

        if (!$last instanceof DateTimeImmutable) {
            return true;
        }

        return $last->modify(sprintf('+%d seconds', $cooldown)) <= $this->clock->now();
    }
}
