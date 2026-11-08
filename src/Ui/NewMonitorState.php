<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Ui;

use Nowo\UiKitBundle\View\Label;

/**
 * New monitor of a tenant dashboard for a host-placed opener (1.22, UM-6; template variable `uptime_new_monitor`, null
 * when the user may not manage the tenant's monitors): the monitor form URL, the button label and, at the monitor
 * limit, the reason it is disabled. Labels are UiKit labels of the bundle domain: `{{ state.reason|nowo_ui_trans }}`.
 */
final readonly class NewMonitorState
{
    /**
     * @param string     $url    Monitor form URL of the tenant
     * @param Label      $label  Button label (`monitor.new`)
     * @param Label|null $reason Why New monitor is disabled (`monitor.limits.max_monitors`); null while allowed
     */
    public function __construct(
        public string $url,
        public Label $label,
        public ?Label $reason = null,
    ) {
    }

    /** Whether the tenant holds as many monitors as its limit allows ({@see self::$reason} says so). */
    public function disabled(): bool
    {
        return $this->reason instanceof Label;
    }
}
