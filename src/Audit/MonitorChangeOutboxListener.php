<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Releases the monitor changes waiting in {@see MonitorChangeOutbox} at the end of every unit of work (since 1.33):
 * `kernel.terminate`, `console.terminate` and `WorkerMessageHandled` end the unit (the committed ones go out, the ones
 * still inside a transaction are dropped); `WorkerMessageFailed` ends it as failed; `kernel.exception` and `console.error`
 * mark it as failed without ending it (a change still inside a transaction keeps waiting for the end of the unit).
 * Only registered with `audit.enabled`.
 * It never touches a request, a response or an entity manager. Stateless.
 */
final readonly class MonitorChangeOutboxListener
{
    /** Listener method per event. */
    public const METHODS = [
        KernelEvents::TERMINATE          => 'onUnitSucceeded',
        ConsoleEvents::TERMINATE         => 'onUnitSucceeded',
        WorkerMessageHandledEvent::class => 'onUnitSucceeded',
        KernelEvents::EXCEPTION          => 'onUnitFailing',
        ConsoleEvents::ERROR             => 'onUnitFailing',
        WorkerMessageFailedEvent::class  => 'onUnitFailed',
    ];

    /**
     * @param MonitorChangeRecorder $recorder Dispatches what the outbox releases
     */
    public function __construct(
        private MonitorChangeRecorder $recorder,
    ) {
    }

    /**
     * The unit of work ended normally.
     */
    public function onUnitSucceeded(): void
    {
        $this->recorder->releaseDeferred(false);
    }

    /**
     * The unit of work ended with an exception (a failed message).
     */
    public function onUnitFailed(): void
    {
        $this->recorder->releaseDeferred(true);
    }

    /**
     * The unit of work met an exception and goes on (`kernel.exception`, `console.error`).
     */
    public function onUnitFailing(): void
    {
        $this->recorder->releaseDeferred(true, false);
    }
}
