<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Monitor\HttpHeaderParser;
use Nowo\UptimeMonitorBundle\Monitor\SpkiPins;
use Nowo\UptimeMonitorBundle\Monitor\StatusCodeMatcher;
use Nowo\UptimeMonitorBundle\Push\PushCredentials;
use Nowo\UptimeMonitorBundle\Push\PushThresholds;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Security\SecretRedactor;

use function array_diff;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Maps form data to Monitor entity fields.
 */
final readonly class MonitorFactory
{
    /** The stored secrets of a monitor that has none (a new one). */
    private const NOTHING_STORED = ['url' => '', 'proxy' => '', 'headers' => null];

    /**
     * Creates the factory.
     *
     * @param MonitorRepository $monitorRepository Resolves the parent group of a monitor
     */
    public function __construct(
        private MonitorRepository $monitorRepository,
    ) {
    }

    /**
     * Applies the submitted form model to the monitor (target, config, schedule, parent group), keeping internal counters.
     */
    public function applyFormData(Monitor $monitor, MonitorFormData $data): void
    {
        $previousConfig    = $monitor->getConfig();
        $stored            = $this->storedSecrets($monitor);
        [$target, $config] = $this->buildTargetAndConfig($data, $stored);
        $config            = $this->preserveInternalConfig($previousConfig, $config, $data, SecretRedactor::sameOrigin($data->url, $stored['url']));

        $monitor
            ->setName($data->name)
            ->setType($data->type)
            ->setTarget($target)
            ->setConfig($config)
            ->setIntervalSeconds(max(30, $data->intervalSeconds))
            ->setRetries($data->retries)
            ->setRetryIntervalSeconds($data->retryIntervalSeconds)
            ->setPaused($data->paused);

        if ($data->type === MonitorType::Push) {
            // A push monitor is judged once per interval: a breach within the retries is re-judged at the next one.
            $monitor->setRetryIntervalSeconds($monitor->getIntervalSeconds());
            PushCredentials::ensureKey($monitor);
        } elseif ($monitor->getPushKey() !== null) {
            // No longer a push monitor: its URL and token stop working.
            $monitor->setPushKey(null)->setPushTokenHash(null);
        }

        if ($data->type === MonitorType::Group) {
            $monitor->setParent(null)->setProject(null);
        } else {
            $monitor->setParent($this->resolveParent($data->parentId, $monitor->getTenant()));
            if (!$monitor->getParent() instanceof Monitor && $data->project !== '') {
                $monitor->setProject($data->project);
            }
        }
    }

    /**
     * The write-only fields whose stored secret a submitted mask (or blank password) would carry to another
     * destination (since 1.25.1): the URL userinfo / token values, the header values and the basic-auth password when
     * the scheme, host or port of the URL changed (the request body too, since 1.25.2), the proxy userinfo / token
     * values when the proxy's changed. They must be typed again (or removed): {@see self::applyFormData()} never
     * restores them across destinations.
     *
     * @return list<'authPassword'|'httpBody'|'httpHeaders'|'proxy'|'url'>
     */
    public function secretsNeedingRetype(Monitor $monitor, MonitorFormData $data): array
    {
        if (!in_array($data->type, [MonitorType::Http, MonitorType::Https], true)) {
            return [];
        }

        $stored = $this->storedSecrets($monitor);
        $fields = [];
        if (!SecretRedactor::sameOrigin($data->url, $stored['url'])) {
            if (SecretRedactor::restoreEditableUrl($data->url, $stored['url']) !== $data->url) {
                $fields[] = 'url';
            }
            $headers = HttpHeaderParser::parseLines($data->httpHeaders);
            if (SecretRedactor::restoreEditableHeaders($headers, $stored['headers']) !== $headers) {
                $fields[] = 'httpHeaders';
            }
            if ($this->keepsStoredBody($monitor->getConfig(), $data)) {
                $fields[] = 'httpBody';
            }
            if ($this->keepsStoredPassword($monitor->getConfig(), $data)) {
                $fields[] = 'authPassword';
            }
        }

        if (!SecretRedactor::sameOrigin($data->proxy, $stored['proxy'])
            && SecretRedactor::restoreEditableUrl($data->proxy, $stored['proxy']) !== $data->proxy) {
            $fields[] = 'proxy';
        }

        return $fields;
    }

    /**
     * The write-only fields that still hold the mask with nothing stored behind it (since 1.25.2): a renamed header
     * (`X-Other: ***`), a new or surplus credential-like parameter (`tokenx=***`), a masked userinfo or body of a new
     * monitor. The form asks for the value (or its removal); {@see self::applyFormData()} drops such a mask instead of
     * saving the literal `***`. Fields {@see self::secretsNeedingRetype()} already reports are left out.
     *
     * @param Monitor|null $monitor The monitor being edited; null for a new one
     *
     * @return list<'httpBody'|'httpHeaders'|'proxy'|'url'>
     */
    public function unresolvedMasks(?Monitor $monitor, MonitorFormData $data): array
    {
        if (!in_array($data->type, [MonitorType::Http, MonitorType::Https], true)) {
            return [];
        }

        $stored                  = $monitor instanceof Monitor ? $this->storedSecrets($monitor) : self::NOTHING_STORED;
        [$url, $proxy, $headers] = $this->restoredHttpSecrets($data, $stored);
        $fields                  = [];
        if (SecretRedactor::stripMaskedUrl($url) !== $url) {
            $fields[] = 'url';
        }
        if (SecretRedactor::stripMaskedHeaders($headers) !== $headers) {
            $fields[] = 'httpHeaders';
        }
        // With the clear box ticked the mask means "remove", not a value.
        if ($data->httpBody === SecretRedactor::MASK && !$data->httpBodyClear && !$this->keepsStoredBody($monitor?->getConfig() ?? [], $data)) {
            $fields[] = 'httpBody';
        }
        if (SecretRedactor::stripMaskedUrl($proxy) !== $proxy) {
            $fields[] = 'proxy';
        }

        $retype = $monitor instanceof Monitor ? $this->secretsNeedingRetype($monitor, $data) : [];

        return array_values(array_diff($fields, $retype));
    }

    /**
     * The stored values behind the masks of the edit form; an HTTP monitor saved without `url` uses its target.
     *
     * @return array{url: string, proxy: string, headers: mixed}
     */
    private function storedSecrets(Monitor $monitor): array
    {
        $config = $monitor->getConfig();

        return [
            'url'     => isset($config['url']) && is_string($config['url']) ? $config['url'] : $monitor->getTarget(),
            'proxy'   => isset($config['proxy']) && is_string($config['proxy']) ? $config['proxy'] : '',
            'headers' => $config['headers'] ?? null,
        ];
    }

    /**
     * Whether a submitted body keeps the stored one (since 1.25.2): blank or the mask, the clear box not ticked, and a
     * non-empty body stored.
     *
     * @param array<string, mixed> $previous
     */
    private function keepsStoredBody(array $previous, MonitorFormData $data): bool
    {
        return ($data->httpBody === '' || $data->httpBody === SecretRedactor::MASK)
            && !$data->httpBodyClear
            && isset($previous['body'])
            && is_string($previous['body'])
            && $previous['body'] !== '';
    }

    /**
     * @param array<string, mixed> $previous
     */
    private function keepsStoredPassword(array $previous, MonitorFormData $data): bool
    {
        return $data->authMethod === 'basic'
            && $data->authPassword === ''
            && !$data->authPasswordClear
            && isset($previous['auth_password'])
            && is_string($previous['auth_password']);
    }

    /**
     * Builds the monitor form model from the monitor and its type-specific config.
     */
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
        $data->requestTimeoutSeconds = $this->wholeSeconds(
            is_numeric($config['request_timeout_seconds'] ?? null) ? $config['request_timeout_seconds'] : ($config['timeout'] ?? null),
            // The default each runner applies when nothing is stored (TCP / SSL 10 s, ping 5 s); 48 s for HTTP.
            match ($monitor->getType()) {
                MonitorType::Tcp, MonitorType::Ssl => 10,
                MonitorType::Ping                  => 5,
                default                            => 48,
            },
        );
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
            MonitorType::Push => $this->mapPushForm($data, $config),
            default           => $this->mapHttpForm($data, $monitor, $config),
        };
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function preserveInternalConfig(array $previous, array $config, MonitorFormData $data, bool $sameUrlOrigin): array
    {
        foreach (['failure_streak', 'down_notify_streak'] as $key) {
            if (isset($previous[$key])) {
                $config[$key] = $previous[$key];
            }
        }

        // Since 1.25.1: never sent to another scheme, host or port (the form asks for it again: secretsNeedingRetype()).
        if ($sameUrlOrigin && $this->keepsStoredPassword($previous, $data)) {
            $config['auth_password'] = $previous['auth_password'];
        }

        // Since 1.25.2 the request body is write-only too, under the same rule.
        if ($sameUrlOrigin && $this->keepsStoredBody($previous, $data)) {
            $config['body'] = $previous['body'];
        }

        return $config;
    }

    private function resolveParent(?int $parentId, Tenant $tenant): ?Monitor
    {
        if ($parentId === null) {
            return null;
        }

        $parent = $this->monitorRepository->find($parentId);

        // Defence in depth: a group of another tenant is never accepted as parent.
        return $parent !== null && $parent->isGroup() && $parent->getTenant() === $tenant ? $parent : null;
    }

    /**
     * Creates a new monitor of the tenant from the submitted form model.
     */
    public function createFromFormData(Tenant $tenant, MonitorFormData $data): Monitor
    {
        [$target] = $this->buildTargetAndConfig($data);
        $monitor  = new Monitor($tenant, $data->name, $data->type, $target);
        $this->applyFormData($monitor, $data);

        return $monitor;
    }

    /**
     * @param array{url: string, proxy: string, headers: mixed} $stored The secrets of the monitor being edited
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildTargetAndConfig(MonitorFormData $data, array $stored = self::NOTHING_STORED): array
    {
        return match ($data->type) {
            MonitorType::Group => [
                $data->name !== '' ? $data->name : 'group',
                [],
            ],
            MonitorType::Ping => [
                $data->host,
                ['host' => $data->host] + $this->timeoutConfig($data),
            ],
            MonitorType::Tcp => [
                sprintf('%s:%d', $data->host, $data->port),
                ['host' => $data->host, 'port' => $data->port] + $this->timeoutConfig($data),
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
                ] + $this->timeoutConfig($data) + $this->spkiPinsConfig($data),
            ],
            MonitorType::Push => ['push', $this->buildPushConfig($data)],
            default           => $this->buildHttpTargetAndConfig($data, $stored),
        };
    }

    /**
     * The expected SPKI pins of an SSL monitor (since 1.20): `expected_spki_pins` only when the form lists some, so a
     * monitor without pins keeps the config of earlier versions. The form already refused malformed or too many pins.
     *
     * @return array{expected_spki_pins?: list<string>}
     */
    private function spkiPinsConfig(MonitorFormData $data): array
    {
        $pins = SpkiPins::parse($data->expectedSpkiPins);

        return $pins === [] ? [] : ['expected_spki_pins' => $pins];
    }

    /**
     * The timeout of a TCP, SSL or ping check (since 1.18; the runners read `timeout`, which only HTTP monitors saved
     * before): whole seconds, at least 1.
     *
     * @return array{timeout: int, request_timeout_seconds: int}
     */
    private function timeoutConfig(MonitorFormData $data): array
    {
        $seconds = max(1, $data->requestTimeoutSeconds);

        return ['timeout' => $seconds, 'request_timeout_seconds' => $seconds];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPushConfig(MonitorFormData $data): array
    {
        $thresholds = [];
        foreach (PushThresholds::DEFAULTS as $metric => $_) {
            $thresholds[$metric] = [$data->pushThresholds[$metric . 'Warn'] ?? null, $data->pushThresholds[$metric . 'Critical'] ?? null];
        }
        $config = [
            'push_signed'                    => $data->pushSigned,
            'push_thresholds'                => $thresholds,
            'resend_notification_after_down' => max(0, $data->resendNotificationAfterDown),
        ];
        if ($data->pushGraceSeconds !== null) {
            $config['push_grace_seconds'] = $data->pushGraceSeconds;
        }
        if ($data->description !== '') {
            $config['description'] = $data->description;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function mapPushForm(MonitorFormData $data, array $config): MonitorFormData
    {
        $data->pushSigned       = ($config['push_signed'] ?? false) === true;
        $data->pushGraceSeconds = isset($config['push_grace_seconds']) && is_int($config['push_grace_seconds']) ? $config['push_grace_seconds'] : null;
        foreach (PushThresholds::fromConfig($config)->limits as $metric => [$warn, $critical]) {
            $data->pushThresholds[$metric . 'Warn']     = $warn;
            $data->pushThresholds[$metric . 'Critical'] = $critical;
        }

        return $data;
    }

    /**
     * Where the submitted URL, proxy or header values still hold the mask of the edit form, the stored secrets are
     * kept (since 1.25.1, {@see SecretRedactor::restoreEditableUrl()}), only for the same scheme, host and port: the URL
     * one for its userinfo, token values and the headers, the proxy one for the proxy's. A mask left with nothing
     * behind it (across destinations, or never stored) is dropped, never saved as `***` (since 1.25.2).
     *
     * @param array{url: string, proxy: string, headers: mixed} $stored
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildHttpTargetAndConfig(MonitorFormData $data, array $stored): array
    {
        $codes                   = StatusCodeMatcher::parse($data->expectedStatusCodes);
        [$url, $proxy, $headers] = $this->restoredHttpSecrets($data, $stored);
        $url                     = SecretRedactor::stripMaskedUrl($url);
        $proxy                   = SecretRedactor::stripMaskedUrl($proxy);
        $headers                 = SecretRedactor::stripMaskedHeaders($headers);

        $config = [
            'url'                            => $url,
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

        // Blank or the mask: preserveInternalConfig() keeps the stored body (same origin only).
        if ($data->httpBody !== '' && $data->httpBody !== SecretRedactor::MASK) {
            $config['body'] = $data->httpBody;
        }

        if ($headers !== []) {
            $config['headers'] = $headers;
        }

        if ($proxy !== '') {
            $config['proxy'] = $proxy;
        }

        if ($data->authMethod === 'basic' && $data->authUsername !== '') {
            $config['auth_username'] = $data->authUsername;
            if ($data->authPassword !== '') {
                $config['auth_password'] = $data->authPassword;
            }
        }

        if ($data->tags !== '') {
            $config['tags'] = array_values(array_filter(array_map(
                trim(...),
                explode(',', $data->tags),
            )));
        }

        return [$url, $config];
    }

    /**
     * The URL, proxy and headers with the stored secrets put back where the submitted values still hold the mask, only
     * for the same origin (the URL's for its userinfo, token values and headers, the proxy's for the proxy).
     *
     * @param array{url: string, proxy: string, headers: mixed} $stored
     *
     * @return array{0: string, 1: string, 2: array<string, string>}
     */
    private function restoredHttpSecrets(MonitorFormData $data, array $stored): array
    {
        $sameUrl = SecretRedactor::sameOrigin($data->url, $stored['url']);
        $url     = $sameUrl ? SecretRedactor::restoreEditableUrl($data->url, $stored['url']) : $data->url;
        $proxy   = SecretRedactor::sameOrigin($data->proxy, $stored['proxy']) ? SecretRedactor::restoreEditableUrl($data->proxy, $stored['proxy']) : $data->proxy;
        $headers = HttpHeaderParser::parseLines($data->httpHeaders);
        if ($sameUrl) {
            $headers = SecretRedactor::restoreEditableHeaders($headers, $stored['headers']);
        }

        return [$url, $proxy, $headers];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function mapHttpForm(MonitorFormData $data, Monitor $monitor, array $config): MonitorFormData
    {
        // Write-only secrets (since 1.25.1): the URL / proxy userinfo, credential-like query values and every header
        // value are shown as the mask; a submitted mask keeps the stored value (buildHttpTargetAndConfig()).
        $data->url    = SecretRedactor::maskEditableUrl(isset($config['url']) && is_string($config['url']) ? $config['url'] : $monitor->getTarget());
        $data->method = isset($config['method']) && is_string($config['method']) ? $config['method'] : 'GET';
        $codes        = $config['expected_status_codes'] ?? [200];
        if (is_array($codes)) {
            // Stored expanded (200, 201, …, 299): shown compact again (200-299), so an edit does not spell out ranges.
            $data->expectedStatusCodes = StatusCodeMatcher::format($codes);
        }
        $data->keyword      = isset($config['keyword']) && is_string($config['keyword']) ? $config['keyword'] : '';
        $data->maxRedirects = isset($config['max_redirects']) && is_numeric($config['max_redirects'])
            ? (int) $config['max_redirects']
            : 10;
        $data->ignoreTls       = ($config['verify_ssl'] ?? true) === false || ($config['ignore_tls'] ?? false) === true;
        $data->upsideDown      = ($config['upside_down'] ?? false) === true;
        $data->checkCertExpiry = ($config['check_cert_expiry'] ?? false) === true;
        // Write-only (since 1.25.2): a stored body shows as the mask; blank or the mask keeps it, the clear box removes it.
        $data->httpBody     = isset($config['body']) && is_string($config['body']) && $config['body'] !== '' ? SecretRedactor::MASK : '';
        $data->bodyEncoding = isset($config['body_encoding']) && is_string($config['body_encoding'])
            ? $config['body_encoding']
            : 'json';
        $headers            = $config['headers'] ?? [];
        $data->httpHeaders  = is_array($headers) ? HttpHeaderParser::format(SecretRedactor::maskEditableHeaders($headers)) : '';
        $data->proxy        = isset($config['proxy']) && is_string($config['proxy']) ? SecretRedactor::maskEditableUrl($config['proxy']) : '';
        $data->authMethod   = isset($config['auth_method']) && is_string($config['auth_method']) ? $config['auth_method'] : 'none';
        $data->authUsername = isset($config['auth_username']) && is_string($config['auth_username'])
            ? $config['auth_username']
            : '';
        // Write-only (since 1.25): the stored password never goes back to the browser.
        $data->authPassword = '';
        $tags               = $config['tags'] ?? [];
        if (is_array($tags)) {
            $data->tags = implode(', ', array_map(strval(...), $tags));
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
        $data->expectedSpkiPins = SpkiPins::toFormText($config['expected_spki_pins'] ?? null);

        return $data;
    }

    /**
     * A stored timeout as whole seconds (at least 1): older monitors may hold a decimal (`12.0`, `0.5`), rounded up so
     * an edit never shortens the timeout; a missing or non-numeric value gives the default.
     */
    private function wholeSeconds(mixed $value, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max(1, (int) ceil((float) $value));
    }
}
