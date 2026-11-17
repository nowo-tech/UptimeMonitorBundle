<?php

declare(strict_types=1);

namespace App\EventListener;

use Nowo\UptimeMonitorBundle\Event\MonitorShowLiveUpdatesEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Demo of MonitorShowLiveUpdatesEvent (UptimeMonitorBundle 1.30): `?live=paused` on a monitor page turns the history
 * polling off (no data-api-history) and marks the script root; `?live=notify` keeps no polling and adds a watcher
 * attribute with the bundle URL, as a host's own live-updates peer would. Reads the current request on each call
 * (no state kept between requests: FrankenPHP worker mode).
 */
#[AsEventListener]
final readonly class DemoLiveUpdatesListener
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function __invoke(MonitorShowLiveUpdatesEvent $event): void
    {
        $mode = $this->requests->getCurrentRequest()?->query->getString('live');
        if ($mode !== 'paused' && $mode !== 'notify') {
            return;
        }
        $event->setApiHistoryUrl(null);
        $event->setRootAttribute('data-demo-live', $mode);
        if ($mode === 'notify') {
            $event->setRootAttribute('data-demo-live-watch-url', $event->historyUrl);
        }
    }
}
