<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form\Model;

/** Form model of Settings → Status page (since 1.13). */
final class SettingsStatusPageData
{
    /** `public`, `private` or `default` (follows `status_page.default_public`). */
    public string $visibility = 'default';

    /** @var list<int> Monitors the public page shows; none chosen: every active monitor. */
    public array $monitorIds = [];
}
