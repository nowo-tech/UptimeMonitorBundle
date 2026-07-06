<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form\Model;

use Nowo\UptimeMonitorBundle\Enum\MonitorType;

/**
 * Form model for monitor CRUD (HTTP, TCP, DNS, SSL).
 */
final class MonitorFormData
{
    public string $name    = '';
    public string $project = '';
    /** Parent group monitor id (Uptime Kuma monitor group). */
    public ?int $parentId                   = null;
    public MonitorType $type                = MonitorType::Https;
    public int $intervalSeconds             = 60;
    public int $retries                     = 5;
    public int $retryIntervalSeconds        = 60;
    public float $requestTimeoutSeconds     = 48;
    public int $resendNotificationAfterDown = 0;
    public bool $paused                     = false;
    public string $description              = '';

    // HTTP(S)
    public string $url                 = '';
    public string $method              = 'GET';
    public string $expectedStatusCodes = '200-299';
    public string $keyword             = '';
    public int $maxRedirects           = 10;
    public bool $ignoreTls             = false;
    public bool $upsideDown            = false;
    public bool $checkCertExpiry       = false;
    public string $httpBody            = '';
    public string $bodyEncoding        = 'json';
    public string $httpHeaders         = '';
    public string $proxy               = '';
    public string $authMethod          = 'none';
    public string $authUsername        = '';
    public string $authPassword        = '';
    public string $tags                = '';

    // TCP / SSL
    public string $host = '';
    public int $port    = 443;

    // DNS
    public string $hostname         = '';
    public string $recordType       = 'A';
    public string $expectedDnsValue = '';

    // SSL
    public int $daysBeforeExpiry = 14;
}
