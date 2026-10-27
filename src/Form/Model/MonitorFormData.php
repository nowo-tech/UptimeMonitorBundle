<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form\Model;

use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Translation\UptimeTranslation;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function in_array;
use function is_string;
use function parse_url;
use function preg_match;
use function strtolower;

use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * Form model for monitor CRUD (HTTP, TCP, DNS, SSL).
 *
 * The range constraints apply to the monitor form and to backup import rows alike (SEC-004 L7).
 */
final class MonitorFormData
{
    public string $name    = '';
    public string $project = '';
    /** Parent group monitor id (Uptime Kuma monitor group). */
    public ?int $parentId    = null;
    public MonitorType $type = MonitorType::Https;
    #[Assert\Range(min: 1, max: 31_536_000)]
    public int $intervalSeconds = 60;
    #[Assert\Range(min: 0, max: 100)]
    public int $retries = 5;
    #[Assert\Range(min: 1, max: 31_536_000)]
    public int $retryIntervalSeconds = 60;
    #[Assert\Range(min: 1, max: 3600)]
    public float $requestTimeoutSeconds = 48;
    #[Assert\Range(min: 0, max: 10_000)]
    public int $resendNotificationAfterDown = 0;
    public bool $paused                     = false;
    public string $description              = '';

    // HTTP(S)
    #[Assert\Callback([self::class, 'validateUrl'])]
    public string $url = '';
    #[Assert\Choice(choices: ['GET', 'HEAD', 'POST'])]
    public string $method              = 'GET';
    public string $expectedStatusCodes = '200-299';
    public string $keyword             = '';
    #[Assert\Range(min: 0, max: 20)]
    public int $maxRedirects     = 10;
    public bool $ignoreTls       = false;
    public bool $upsideDown      = false;
    public bool $checkCertExpiry = false;
    public string $httpBody      = '';
    public string $bodyEncoding  = 'json';
    public string $httpHeaders   = '';
    public string $proxy         = '';
    public string $authMethod    = 'none';
    public string $authUsername  = '';
    public string $authPassword  = '';
    public string $tags          = '';

    // TCP / SSL
    public string $host = '';
    #[Assert\Range(min: 0, max: 65535)]
    public int $port = 443;

    // DNS
    public string $hostname         = '';
    public string $recordType       = 'A';
    public string $expectedDnsValue = '';

    // SSL
    #[Assert\Range(min: 0, max: 3650)]
    public int $daysBeforeExpiry = 14;

    /**
     * HTTP / HTTPS monitors (SEC-004 I2): a non-empty URL must be an absolute http:// or https:// URL with a host and
     * no whitespace (`form.monitor.url_invalid`); other monitor types ignore the field.
     */
    public static function validateUrl(mixed $value, ExecutionContextInterface $context): void
    {
        $data = $context->getObject();
        if (!is_string($value) || $value === '' || ($data instanceof self && !in_array($data->type, [MonitorType::Http, MonitorType::Https], true))) {
            return;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host   = parse_url($value, PHP_URL_HOST);
        if (is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) && is_string($host) && $host !== '' && preg_match('/\s/', $value) !== 1) {
            return;
        }

        $context->buildViolation('form.monitor.url_invalid')
            ->setTranslationDomain(UptimeTranslation::DOMAIN)
            ->addViolation();
    }
}
