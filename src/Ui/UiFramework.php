<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Ui;

/**
 * Dashboard / UI styling stack for Twig templates.
 */
enum UiFramework: string
{
    /** Tabler + Nowo bundle styles (default for nowoDevKit). */
    case Tabler = 'tabler';

    /** Bundle BEM styles only ({@code uptime-dashboard.css}). */
    case Custom = 'custom';

    case Bootstrap = 'bootstrap';

    case Tailwind = 'tailwind';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Tabler;
    }
}
