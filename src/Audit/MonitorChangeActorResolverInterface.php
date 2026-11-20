<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Nowo\UptimeMonitorBundle\Event\MonitorChangedEvent;

/**
 * Who changes monitors (since 1.33): the identifier recorded in the history and carried by
 * {@see MonitorChangedEvent}. The default reads the user identifier of the security
 * token ({@see TokenStorageActorResolver}); a host sets `audit.actor_resolver` to record something else (a user UUID
 * instead of an e-mail address, which keeps the history free of direct identifiers).
 *
 * Called once per recorded change, inside the transaction of the change: implementations must not write, and must
 * keep no per-request state (FrankenPHP worker mode).
 */
interface MonitorChangeActorResolverInterface
{
    /**
     * The actor of the change being written; null when there is none (a console command, a scheduled job).
     */
    public function actorId(): ?string;
}
