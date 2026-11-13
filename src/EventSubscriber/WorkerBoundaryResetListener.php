<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\EventSubscriber;

use Nowo\UptimeMonitorBundle\Doctrine\UnitOfWorkBoundaries;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Drops the bundle's private entity manager at the OUTERMOST unit-of-work boundaries (1.27.0; outermost only since
 * 1.28.0, REQ-RUNTIME-002 rule 4).
 *
 * `kernel.reset` alone is not enough: it does not run where the services resetter does not, i.e. a host that removes
 * or bypasses `services_resetter` (FrankenPHP worker mode with the kernel kept alive, a host driving `http_kernel`
 * directly), a Messenger consumer started with `--no-reset` (the scheduler transport) or a long-running console
 * command. Without this listener such a process kept the monitors, tenants and results of its first unit in the
 * private identity map and never saw a change saved by another process.
 *
 * Boundaries, judged by {@see UnitOfWorkBoundaries} (which tracks the running units at the extreme priorities):
 * - HTTP: the main `kernel.request` and `kernel.terminate`, only outside a console command and a Messenger message,
 *   and a main request only outside another main request (audit NX CR2). A request handled in-process by a command, a
 *   message handler or a controller does not drop the manager of the unit that runs it; sub-requests share their main
 *   request's unit of work;
 * - Messenger: `WorkerMessageReceived` / `Handled` / `Failed`, only outside another message (a worker run in-process
 *   by a handler does not drop the outer message's manager, audit NX CR1);
 * - console: `console.command` / `console.terminate` of the OUTERMOST command only. A command run in-process from
 *   another command, a message handler or a controller does not drop the outer unit's manager (up to 1.27.0 it did,
 *   detaching every bundle entity the outer unit still held).
 *
 * The manager is injected as `IGNORE_ON_UNINITIALIZED_REFERENCE`, so a boundary never builds it. Every bundle write
 * unit flushes before it returns; changes still pending at a boundary are logged by
 * {@see UptimeEntityManager::reset()}.
 */
final readonly class WorkerBoundaryResetListener
{
    /** Priority of the start-of-unit boundaries (before anything reads a bundle entity). */
    public const START_PRIORITY = 2048;

    /** Priority of the end-of-unit boundaries (after every other listener). */
    public const END_PRIORITY = -2048;

    /** Messenger worker events (::class constants load no class). */
    public const MESSENGER_RECEIVED = WorkerMessageReceivedEvent::class;

    public const MESSENGER_HANDLED = WorkerMessageHandledEvent::class;

    public const MESSENGER_FAILED = WorkerMessageFailedEvent::class;

    /** Start-of-unit events (the main request goes through {@see onKernelRequest()}). */
    public const START_EVENTS = [self::MESSENGER_RECEIVED, ConsoleEvents::COMMAND];

    /** End-of-unit events. */
    public const END_EVENTS = [KernelEvents::TERMINATE, self::MESSENGER_HANDLED, self::MESSENGER_FAILED, ConsoleEvents::TERMINATE];

    /** Listener method per boundary event (1.28.0). */
    public const METHODS = [
        self::MESSENGER_RECEIVED => 'onUnitBoundary',
        self::MESSENGER_HANDLED  => 'onUnitBoundary',
        self::MESSENGER_FAILED   => 'onUnitBoundary',
        ConsoleEvents::COMMAND   => 'onUnitBoundary',
        ConsoleEvents::TERMINATE => 'onUnitBoundary',
        KernelEvents::TERMINATE  => 'onKernelTerminate',
    ];

    /**
     * @param UptimeEntityManager|null $entityManager the bundle's private manager, when the process already used it
     * @param UnitOfWorkBoundaries|null $boundaries the unit tracker (null: every boundary drops the manager, as in 1.27)
     */
    public function __construct(
        private ?UptimeEntityManager $entityManager = null,
        private ?UnitOfWorkBoundaries $boundaries = null,
    ) {
    }

    /**
     * Main request start, outside a command, a message and another main request (audit NX CR2); sub-requests share the
     * main request's unit of work.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->onUnitBoundary($event);
        }
    }

    /**
     * End of a request, outside a command and a message (an in-process `HttpKernel::handle()` keeps the outer unit).
     */
    public function onKernelTerminate(?TerminateEvent $event = null): void
    {
        if ($event instanceof TerminateEvent) {
            $this->onUnitBoundary($event);

            return;
        }

        // Called without its event (before 1.28.3): judged on the current state.
        if (!$this->boundaries instanceof UnitOfWorkBoundaries || $this->boundaries->isHttpOutermost()) {
            $this->onBoundary();
        }
    }

    /**
     * Start or end of a command or a Messenger message: drops the manager only for the outermost one (a command
     * outside a message and a main request; a message outside another message, e.g. a worker run in-process by a
     * handler, audit NX CR1).
     */
    public function onUnitBoundary(object $event): void
    {
        if (!$this->boundaries instanceof UnitOfWorkBoundaries || $this->boundaries->isOutermost($event)) {
            $this->onBoundary();
        }
    }

    /**
     * Start or end of a command (kept for callers of 1.28.x; same as {@see onUnitBoundary()}).
     */
    public function onConsoleBoundary(object $event): void
    {
        $this->onUnitBoundary($event);
    }

    /**
     * Drops the private manager (recreated on next use).
     */
    public function onBoundary(): void
    {
        $this->entityManager?->reset();
    }
}
