<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Doctrine\Common\EventManagerInterface;
use Doctrine\DBAL\Connection;

/**
 * One run of the bundle's transaction runner as the audit sees it (since 1.33): the outermost run owns its
 * {@see MonitorChangeCapture} (attached on open, detached when the run ends); a nested run shares the outer capture and
 * only marks where its changes start.
 *
 * Exactly one of {@see self::succeed()} / {@see self::fail()} is called by the runner. Lives for one call only.
 *
 * @internal
 */
final readonly class MonitorChangeScope
{
    /**
     * @param MonitorChangeCapture $capture The capture of the outermost run
     * @param EventManagerInterface $eventManager Where the capture is attached
     * @param Connection $connection The bundle connection (still in a transaction after the run: an outer one is open)
     * @param MonitorChangeRecorder $recorder Dispatches the events after the commit
     * @param bool $owner Whether this run attached the capture (the outermost run)
     * @param int $mark Where a nested run's changes start
     * @param array{0?: string, 1?: string} $historyLocation Table and id column of the history (empty without it)
     */
    public function __construct(
        private MonitorChangeCapture $capture,
        private EventManagerInterface $eventManager,
        private Connection $connection,
        private MonitorChangeRecorder $recorder,
        private bool $owner,
        private int $mark,
        private array $historyLocation = [],
    ) {
    }

    /**
     * The run committed (or released its savepoint): the outermost run dispatches its changes, after the commit.
     */
    public function succeed(): void
    {
        if (!$this->owner) {
            return;
        }
        $this->detach();
        $this->recorder->release($this->capture->recorded(), $this->connection, $this->capture->historyIds(), ...$this->historyLocation);
    }

    /**
     * The run failed and was rolled back: its changes are forgotten (their history rows were rolled back with them).
     */
    public function fail(): void
    {
        if ($this->owner) {
            $this->detach();

            return;
        }
        $this->capture->rollbackTo($this->mark);
    }

    private function detach(): void
    {
        $this->eventManager->removeEventListener(MonitorChangeCapture::events(), $this->capture);
    }
}
