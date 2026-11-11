<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Apply\MonitorApplier;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Throwable;

use function in_array;
use function sprintf;
use function trigger_error;

use const E_USER_DEPRECATED;

/**
 * Handles status changes, incidents, and notification cooldown.
 */
final readonly class StatusTransitionService
{
    /**
     * @param array<string, mixed> $notificationsConfig
     * @param LoggerInterface $logger Receives the class of a listener failure after the commit (since 1.25)
     * @param bool $flushListenerChanges `doctrine.flush_listener_changes` (since 1.27.0): also flush what the incident
     *                                   listeners left in the application manager that maps the bundle entities
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IncidentRepository $incidentRepository,
        private NotificationService $notificationService,
        #[Autowire('%nowo_uptime_monitor.notifications%')]
        private array $notificationsConfig,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
        #[Autowire('%nowo_uptime_monitor.doctrine.flush_listener_changes%')]
        private bool $flushListenerChanges = true,
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
     * 1.25), then flushes what the listeners persisted (before 1.25 they shared the check's flush): the bundle manager,
     * and since 1.27.0 the application manager that maps the bundle entities only with `doctrine.flush_listener_changes`
     * and only when nothing was pending there before the listeners ran; changes they leave unflushed otherwise are
     * reported with a warning and a deprecation (see {@see self::afterListeners()}). Nothing to do
     * without events. The check is already stored: a listener that throws is logged (its class only) and the other
     * events, the flush and the caller's dashboard sync / group refresh still run, like {@see MonitorApplier}.
     *
     * @param list<MonitorRecoveredEvent|MonitorWentDownEvent> $events
     */
    public function dispatchCommitted(array $events): void
    {
        if ($events === []) {
            return;
        }

        // 1.27.0: the application manager is flushed after the listeners only when nothing was pending there before
        // them (rule 6: a change the application left pending is never flushed by the bundle); what they leave unflushed
        // otherwise is reported (MQ-U-M1).
        $watch = $this->watchApplicationManager($events);

        foreach ($events as $event) {
            try {
                $this->eventDispatcher?->dispatch($event);
            } catch (Throwable $failure) {
                $this->logger->error('Uptime incident event listener failed after the commit: {error}', [
                    'bundle'     => 'NowoUptimeMonitorBundle',
                    'event'      => $event::class,
                    'monitor_id' => $event->getMonitorId(),
                    'error'      => $failure::class,
                ]);
            }
        }

        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            $this->entityManager->flush();
            if ($watch !== null) {
                $this->afterListeners($watch[0], $watch[1], $events);
            }
        }
    }

    /**
     * The application manager that maps the bundle entities and whether it already had pending changes before the
     * incident listeners ran. Null (nothing to watch) without a dispatcher, without the bundle's private manager, when
     * no listener is registered for these events, or when that manager is closed.
     *
     * @param list<MonitorRecoveredEvent|MonitorWentDownEvent> $events
     *
     * @return array{0: EntityManagerInterface, 1: bool}|null
     */
    private function watchApplicationManager(array $events): ?array
    {
        if (!$this->eventDispatcher instanceof EventDispatcherInterface || !$this->entityManager instanceof UptimeEntityManager || !$this->hasListeners($events)) {
            return null;
        }

        $manager = $this->entityManager->mappingManager();

        return $manager->isOpen() ? [$manager, $this->hasPendingChanges($manager)] : null;
    }

    /**
     * After the incident listeners: what they left in the application manager is flushed only with
     * `doctrine.flush_listener_changes` and only when nothing was pending there before them (1.25 to 1.26 behaviour,
     * kept for BC with a deprecation). Any other change left there is not flushed by the bundle (a change the
     * application left pending must never be committed by it, rule 6) and is reported with a warning and a
     * deprecation: listeners must flush their own changes (MQ-U-M1).
     *
     * @param list<MonitorRecoveredEvent|MonitorWentDownEvent> $events
     */
    private function afterListeners(EntityManagerInterface $manager, bool $pendingBefore, array $events): void
    {
        if (!$manager->isOpen() || !$this->hasPendingChanges($manager)) {
            return;
        }

        if (!$this->flushListenerChanges || $pendingBefore) {
            $this->logger->warning('Uptime incident listeners left changes in an application entity manager that the bundle does not flush: {event}', [
                'bundle'         => 'NowoUptimeMonitorBundle',
                'event'          => $events[0]::class,
                'monitor_id'     => $events[0]->getMonitorId(),
                'pending_before' => $pendingBefore,
            ]);
            @trigger_error(sprintf('Since nowo-tech/uptime-monitor-bundle 1.27.0: a listener of %s left changes in an application entity manager that the bundle does not flush (%s). Before 1.27 the bundle flushed them; flush them in the listener.', $events[0]::class, $pendingBefore ? 'the manager already had pending changes of the application' : 'doctrine.flush_listener_changes is false'), E_USER_DEPRECATED);

            return;
        }

        @trigger_error(sprintf('Since nowo-tech/uptime-monitor-bundle 1.27.0: a listener of %s left changes in an application entity manager; the bundle flushes them for backward compatibility. Flush them in the listener and set nowo_uptime_monitor.doctrine.flush_listener_changes to false: 2.0 never flushes an application entity manager.', $events[0]::class), E_USER_DEPRECATED);

        try {
            $manager->flush();
        } catch (Throwable $failure) {
            $this->logger->error('Uptime incident listener changes could not be flushed: {error}', [
                'bundle'     => 'NowoUptimeMonitorBundle',
                'monitor_id' => $events[0]->getMonitorId(),
                'error'      => $failure::class,
            ]);
        }
    }

    /**
     * Whether a listener is registered for one of the events (any PSR-14 dispatcher other than Symfony's: assumed yes).
     *
     * @param list<MonitorRecoveredEvent|MonitorWentDownEvent> $events
     */
    private function hasListeners(array $events): bool
    {
        if (!$this->eventDispatcher instanceof SymfonyEventDispatcherInterface) {
            return true;
        }

        foreach ($events as $event) {
            if ($this->eventDispatcher->hasListeners($event::class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the application manager has pending changes. Computing them reads every managed entity; a failure (an
     * entity the application left half-built) counts as pending, so it is never flushed by the bundle (MQ-U-L1).
     */
    private function hasPendingChanges(EntityManagerInterface $manager): bool
    {
        try {
            $unitOfWork = $manager->getUnitOfWork();
            $unitOfWork->computeChangeSets();
        } catch (Throwable) {
            return true;
        }

        return $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== []
            || $unitOfWork->getScheduledCollectionUpdates() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== [];
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
