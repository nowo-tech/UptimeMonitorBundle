<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form\Model;

/** Form model for Settings → Monitor history. */
final class SettingsHistoryData
{
    /** 0 = infinite retention (Uptime Kuma semantics). */
    public int $detailDays = 180;

    public bool $useGlobalDefault = false;
}
