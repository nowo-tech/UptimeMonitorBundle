<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form\Model;

/** Form model for Settings → General. */
final class SettingsGeneralData
{
    public string $displayTimezone    = 'auto';
    public string $serverTimezone     = 'UTC';
    public bool $searchEngineIndex    = false;
    public string $entryPage          = 'dashboard';
    public string $primaryBaseUrl     = '';
    public string $steamApiKey        = '';
    public bool $nscdEnabled          = true;
    public bool $httpDnsCache         = false;
    public string $chromiumExecutable = 'auto';
}
