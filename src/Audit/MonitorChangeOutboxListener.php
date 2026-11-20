<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Releases the monitor changes waiting in {@see MonitorChangeOutbox} at the end of every unit of work (since 1.33):
 * `kernel.terminate`, `console.terminate` and `WorkerMessageHandled` release the committed ones; `kernel.exception`,
 * `console.error` and `WorkerMessageFailed` tell the outbox the unit failed. Only registered with `audit.enabled`.
 * It never touches a request, a response or an entity manager. Stateless.
 */
final readonly class MonitorChangeOutboxListener
{
    /** Listener method per event. */
    public const METHODS = [
        KernelEvents::TERMINATE          => 'onUnitSucceeded',
        ConsoleEvents::TERMINATE         => 'onUnitSucceeded',
        WorkerMessageHandledEvent::class => 'onUnitSucceeded',
        KernelEvents::EXCEPTION          => 'onUnitFailed',
        ConsoleEvents::ERROR             => 'onUnitFailed',
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
     * The unit of work ended with an exception.
     */
    public function onUnitFailed(): void
    {
        $this->recorder->releaseDeferred(true);
    }
}
