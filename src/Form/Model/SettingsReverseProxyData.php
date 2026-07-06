<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form\Model;

/** Form model for Settings → Reverse proxy. */
final class SettingsReverseProxyData
{
    public bool $trustedProxy = false;
}
