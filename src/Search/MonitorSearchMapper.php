<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;

/**
 * Maps a monitor to a search result (since 1.32). Only the id, name, type, tenant slug, the origin of the target and,
 * when asked, the last known status are read: the config (headers, auth, body), push credentials and notes are not.
 */
final readonly class MonitorSearchMapper
{
    /**
     * @param bool $includeStatus Whether the viewer may see the status (the caller decides)
     */
    public function map(Monitor $monitor, bool $includeStatus): MonitorSearchHit
    {
        $type = $monitor->getType();

        return new MonitorSearchHit(
            (int) $monitor->getId(),
            $monitor->getName(),
            $type->value,
            $monitor->getTenant()->getSlug(),
            self::hasTarget($type) ? MonitorSearchUrl::display($monitor->getTarget()) : '',
            $includeStatus ? $monitor->getLastKnownStatus()?->value : null,
        );
    }

    /**
     * Groups have no target and a push monitor's target is not a URL to show or match.
     */
    public static function hasTarget(MonitorType $type): bool
    {
        return $type !== MonitorType::Group && $type !== MonitorType::Push;
    }
}
