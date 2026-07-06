<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form\Model;

/** Form model for Settings → Appearance. */
final class SettingsAppearanceData
{
    public string $theme             = 'auto';
    public string $heartbeatBarTheme = 'normal';
    public string $elapsedTime       = 'show';

    /** default | custom | bootstrap | tailwind */
    public string $uiFramework = 'default';
}
