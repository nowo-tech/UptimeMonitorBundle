<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\EventSubscriber;

use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Drops the bundle's private entity manager at every unit-of-work boundary (1.27.0, REQ-RUNTIME-002).
 *
 * `kernel.reset` alone is not enough: it does not run where the services resetter does not, i.e. a host that removes
 * or bypasses `services_resetter` (FrankenPHP worker mode with the kernel kept alive, a host driving `http_kernel`
 * directly), a Messenger consumer started with `--no-reset` (the scheduler transport) or a long-running console
 * command. Without this listener such a process kept the monitors, tenants and results of its first unit in the
 * private identity map and never saw a change saved by another process.
 *
 * Boundaries: the main `kernel.request` and `kernel.terminate`; Messenger `WorkerMessageReceived` / `Handled` /
 * `Failed`; `console.command` and `console.terminate`. The manager is injected as
 * `IGNORE_ON_UNINITIALIZED_REFERENCE`, so a boundary never builds it. Every bundle write unit flushes before it
 * returns, so dropping the manager at a boundary never drops a pending bundle write.
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

    /** Start-of-unit events handled by {@see onBoundary()} (the main request goes through {@see onKernelRequest()}). */
    public const START_EVENTS = [self::MESSENGER_RECEIVED, ConsoleEvents::COMMAND];

    /** End-of-unit events handled by {@see onBoundary()}. */
    public const END_EVENTS = [KernelEvents::TERMINATE, self::MESSENGER_HANDLED, self::MESSENGER_FAILED, ConsoleEvents::TERMINATE];

    /**
     * @param UptimeEntityManager|null $entityManager the bundle's private manager, when the process already used it
     */
    public function __construct(
        private ?UptimeEntityManager $entityManager = null,
    ) {
    }

    /**
     * Main request start; sub-requests share the main request's unit of work.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->onBoundary();
        }
    }

    /**
     * Any other boundary: drops the private manager (recreated on next use).
     */
    public function onBoundary(): void
    {
        $this->entityManager?->reset();
    }
}
