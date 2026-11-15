<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use RuntimeException;

use function sprintf;

/**
 * 1.29 (Beacon spec 239): the monitor was deleted while its check ran, so the check's outcome is not stored.
 */
final class MonitorGoneException extends RuntimeException
{
    /**
     * @param int $monitorId Id of the deleted monitor
     */
    public function __construct(
        public readonly int $monitorId,
    ) {
        parent::__construct(sprintf('Monitor %d was deleted while its check ran.', $monitorId));
    }
}
