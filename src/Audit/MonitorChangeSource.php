<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

/**
 * The write path recorded with each monitor change (since 1.33).
 */
final class MonitorChangeSource
{
    /** The admin UI: new / edit forms, delete, pause / resume, push token issue / revoke. */
    public const UI = 'ui';

    /** The backup import (settings page). */
    public const IMPORT = 'import';

    /** `nowo:uptime:apply-monitors` (a monitors file). */
    public const APPLY = 'apply';

    /** `nowo:uptime:seed-demo`. */
    public const DEMO = 'demo';

    /** Anything else: host code and console commands that write monitors through the bundle services. */
    public const APP = 'app';
}
