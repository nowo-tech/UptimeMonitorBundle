<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

use Nowo\UptimeMonitorBundle\Event\MonitorsAppliedEvent;

/**
 * Programmatic entry point of `nowo:uptime:apply-monitors` (since 1.25): reconciles the push monitors of a monitors
 * file with the database. {@see MonitorApplier} is the implementation; type-hint this interface in host code (and
 * decorate or replace it in tests) instead of the final class.
 */
interface MonitorApplierInterface
{
    /**
     * Applies (or, with `$dryRun`, plans) the file. All or nothing; {@see MonitorsAppliedEvent}
     * is dispatched per tenant after the commit.
     *
     * @param MonitorFileSpec $spec Parsed monitors file
     * @param ExternalPushTokens $tokens Push tokens read from stdin or files
     * @param bool $dryRun Plan only, write nothing
     * @param bool $prune Delete the file monitors the file no longer names
     *
     * @throws ApplyRefusedException exit 4–6, nothing written
     * @throws ApplyFailedException the run failed and was rolled back
     */
    public function apply(MonitorFileSpec $spec, ExternalPushTokens $tokens, bool $dryRun, bool $prune): ApplyResult;
}
