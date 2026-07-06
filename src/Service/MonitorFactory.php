<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Monitor\HttpHeaderParser;
use Nowo\UptimeMonitorBundle\Monitor\StatusCodeMatcher;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Maps form data to Monitor entity fields.
 */
final class MonitorFactory
{
    public function __construct(
        private readonly MonitorRepository $monitorRepository,
    ) {
    }

    public function applyFormData(Monitor $monitor, MonitorFormData $data): void
    {
        $previousConfig    = $monitor->getConfig();
        [$target, $config] = $this->buildTargetAndConfig($data);
        $config            = $this->preserveInternalConfig($previousConfig, $config, $data);

        $monitor
            ->setName($data->name)
            ->setType($data->type)
            ->setTarget($target)
            ->setConfig($config)
            ->setIntervalSeconds(max(30, $data->intervalSeconds))
            ->setRetries($data->retries)
            ->setRetryIntervalSeconds($data->retryIntervalSeconds)
            ->setPaused($data->paused);

        if ($data->type === MonitorType::Group) {
            $monitor->setParent(null)->setProject(null);
        } else {
            $monitor->setParent($this->resolveParent($data->parentId));
            if ($monitor->getParent() === null && $data->project !== '') {
                $monitor->setProject($data->project);
            }
        }
    }

    public function toFormData(Monitor $monitor): MonitorFormData
    {
        $config                      = $monitor->getConfig();
        $data                        = new MonitorFormData();
        $data->name                  = $monitor->getName();
        $data->project               = $monitor->getProject() ?? '';
        $data->type                  = $monitor->getType();
        $data->intervalSeconds       = $monitor->getIntervalSeconds();
        $data->retries               = $monitor->getRetries();
        $data->retryIntervalSeconds  = $monitor->getRetryIntervalSeconds();
        $data->requestTimeoutSeconds = isset($config['request_timeout_seconds']) && is_numeric($config['request_timeout_seconds'])
            ? (float) $config['request_timeout_seconds']
            : (isset($config['timeout']) && is_numeric($config['timeout']) ? (float) $config['timeout'] : 48);
        $data->resendNotificationAfterDown = isset($config['resend_notification_after_down']) && is_numeric($config['resend_notification_after_down'])
            ? (int) $config['resend_notification_after_down']
            : 0;
        $data->description = isset($config['description']) && is_string($config['description']) ? $config['description'] : '';
        $data->paused      = $monitor->isPaused();
        $data->parentId    = $monitor->getParent()?->getId();

        if ($monitor->getType() === MonitorType::Group) {
            return $data;
        }

        return match ($monitor->getType()) {
            MonitorType::Tcp  => $this->mapTcpForm($data, $monitor, $config),
            MonitorType::Ping => $this->mapPingForm($data, $monitor, $config),
            MonitorType::Dns  => $this->mapDnsForm($data, $monitor, $config),
            MonitorType::Ssl  => $this->mapSslForm($data, $monitor, $config),
            default           => $this->mapHttpForm($data, $monitor, $config),
        };
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function preserveInternalConfig(array $previous, array $config, MonitorFormData $data): array
    {
        foreach (['failure_streak', 'down_notify_streak'] as $key) {
            if (isset($previous[$key])) {
                $config[$key] = $previous[$key];
            }
        }

        if (
            $data->authMethod === 'basic'
            && $data->authPassword === ''
            && isset($previous['auth_password'])
            && is_string($previous['auth_password'])
        ) {
            $config['auth_password'] = $previous['auth_password'];
        }

        return $config;
    }

    private function resolveParent(?int $parentId): ?Monitor
    {
        if ($parentId === null) {
            return null;
        }

        $parent = $this->monitorRepository->find($parentId);

        return $parent !== null && $parent->isGroup() ? $parent : null;
    }

    public function createFromFormData(Tenant $tenant, MonitorFormData $data): Monitor
    {
        [$target] = $this->buildTargetAndConfig($data);
        $monitor  = new Monitor($tenant, $data->name, $data->type, $target);
        $this->applyFormData($monitor, $data);

        return $monitor;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildTargetAndConfig(MonitorFormData $data): array
    {
        return match ($data->type) {
            MonitorType::Group => [
                $data->name !== '' ? $data->name : 'group',
                [],
            ],
            MonitorType::Ping => [
                $data->host,
                ['host' => $data->host],
            ],
            MonitorType::Tcp => [
                sprintf('%s:%d', $data->host, $data->port),
                ['host' => $data->host, 'port' => $data->port],
            ],
            MonitorType::Dns => [
                $data->hostname,
                [
                    'hostname'       => $data->hostname,
                    'record_type'    => $data->recordType,
                    'expected_value' => $data->expectedDnsValue,
                ],
            ],
            MonitorType::Ssl => [
                sprintf('%s:%d', $data->host, $data->port),
                [
                    'host'               => $data->host,
                    'port'               => $data->port,
                    'days_before_expiry' => $data->daysBeforeExpiry,
                ],
            ],
            default => $this->buildHttpTargetAndConfig($data),
        };
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildHttpTargetAndConfig(MonitorFormData $data): array
    {
        $codes = StatusCodeMatcher::parse($data->expectedStatusCodes);

        $config = [
            'url'                            => $data->url,
            'method'                         => $data->method,
            'expected_status_codes'          => $codes,
            'request_timeout_seconds'        => max(1, $data->requestTimeoutSeconds),
            'timeout'                        => max(1, $data->requestTimeoutSeconds),
            'max_redirects'                  => max(0, $data->maxRedirects),
            'resend_notification_after_down' => max(0, $data->resendNotificationAfterDown),
            'body_encoding'                  => $data->bodyEncoding,
            'auth_method'                    => $data->authMethod,
        ];

        if ($data->description !== '') {
            $config['description'] = $data->description;
        }

        if ($data->ignoreTls) {
            $config['verify_ssl'] = false;
            $config['ignore_tls'] = true;
        }

        if ($data->upsideDown) {
            $config['upside_down'] = true;
        }

        if ($data->checkCertExpiry) {
            $config['check_cert_expiry'] = true;
        }

        if ($data->keyword !== '') {
            $config['keyword'] = $data->keyword;
        }

        if ($data->httpBody !== '') {
            $config['body'] = $data->httpBody;
        }

        $headers = HttpHeaderParser::parseLines($data->httpHeaders);
        if ($headers !== []) {
            $config['headers'] = $headers;
        }

        if ($data->proxy !== '') {
            $config['proxy'] = $data->proxy;
        }

        if ($data->authMethod === 'basic' && $data->authUsername !== '') {
            $config['auth_username'] = $data->authUsername;
            if ($data->authPassword !== '') {
                $config['auth_password'] = $data->authPassword;
            }
        }

        if ($data->tags !== '') {
            $config['tags'] = array_values(array_filter(array_map(
                static fn (string $tag): string => trim($tag),
                explode(',', $data->tags),
            )));
        }

        return [$data->url, $config];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function mapHttpForm(MonitorFormData $data, Monitor $monitor, array $config): MonitorFormData
    {
        $data->url    = isset($config['url']) && is_string($config['url']) ? $config['url'] : $monitor->getTarget();
        $data->method = isset($config['method']) && is_string($config['method']) ? $config['method'] : 'GET';
        $codes        = $config['expected_status_codes'] ?? [200];
        if (is_array($codes)) {
            $data->expectedStatusCodes = implode(',', array_map('strval', $codes));
        }
        $data->keyword      = isset($config['keyword']) && is_string($config['keyword']) ? $config['keyword'] : '';
        $data->maxRedirects = isset($config['max_redirects']) && is_numeric($config['max_redirects'])
            ? (int) $config['max_redirects']
            : 10;
        $data->ignoreTls       = ($config['verify_ssl'] ?? true) === false || ($config['ignore_tls'] ?? false) === true;
        $data->upsideDown      = ($config['upside_down'] ?? false) === true;
        $data->checkCertExpiry = ($config['check_cert_expiry'] ?? false) === true;
        $data->httpBody        = isset($config['body']) && is_string($config['body']) ? $config['body'] : '';
        $data->bodyEncoding    = isset($config['body_encoding']) && is_string($config['body_encoding'])
            ? $config['body_encoding']
            : 'json';
        $headers            = $config['headers'] ?? [];
        $data->httpHeaders  = is_array($headers) ? HttpHeaderParser::format($headers) : '';
        $data->proxy        = isset($config['proxy']) && is_string($config['proxy']) ? $config['proxy'] : '';
        $data->authMethod   = isset($config['auth_method']) && is_string($config['auth_method']) ? $config['auth_method'] : 'none';
        $data->authUsername = isset($config['auth_username']) && is_string($config['auth_username'])
            ? $config['auth_username']
            : '';
        $data->authPassword = isset($config['auth_password']) && is_string($config['auth_password'])
            ? $config['auth_password']
            : '';
        $tags = $config['tags'] ?? [];
        if (is_array($tags)) {
            $data->tags = implode(', ', array_map('strval', $tags));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function mapPingForm(MonitorFormData $data, Monitor $monitor, array $config): MonitorFormData
    {
        $data->host = isset($config['host']) && is_string($config['host']) ? $config['host'] : $monitor->getTarget();

        return $data;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function mapTcpForm(MonitorFormData $data, Monitor $monitor, array $config): MonitorFormData
    {
        $data->host = isset($config['host']) && is_string($config['host']) ? $config['host'] : $monitor->getTarget();
        $data->port = isset($config['port']) && is_numeric($config['port']) ? (int) $config['port'] : 443;

        return $data;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function mapDnsForm(MonitorFormData $data, Monitor $monitor, array $config): MonitorFormData
    {
        $data->hostname = isset($config['hostname']) && is_string($config['hostname'])
            ? $config['hostname']
            : $monitor->getTarget();
        $data->recordType = isset($config['record_type']) && is_string($config['record_type'])
            ? $config['record_type']
            : 'A';
        $data->expectedDnsValue = isset($config['expected_value']) && is_string($config['expected_value'])
            ? $config['expected_value']
            : '';

        return $data;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function mapSslForm(MonitorFormData $data, Monitor $monitor, array $config): MonitorFormData
    {
        $data->host             = isset($config['host']) && is_string($config['host']) ? $config['host'] : $monitor->getTarget();
        $data->port             = isset($config['port']) && is_numeric($config['port']) ? (int) $config['port'] : 443;
        $data->daysBeforeExpiry = isset($config['days_before_expiry']) && is_numeric($config['days_before_expiry'])
            ? (int) $config['days_before_expiry']
            : 14;

        return $data;
    }
}
