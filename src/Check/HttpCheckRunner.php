<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Nowo\UptimeMonitorBundle\Check\Network\SocketConnectorInterface;
use Nowo\UptimeMonitorBundle\Check\Network\StreamSocketConnector;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Monitor\MonitorSettings;
use Nowo\UptimeMonitorBundle\Monitor\StatusCodeMatcher;
use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;
use OpenSSLCertificate;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

use const CASE_LOWER;
use const PHP_URL_HOST;
use const PHP_URL_PORT;

/**
 * Executes HTTP/HTTPS monitor checks via Symfony HttpClient.
 *
 * When private targets are blocked, the client is wrapped in {@see NoPrivateNetworkHttpClient} so every redirect hop
 * and the actually connected IP are re-validated (DNS rebinding), and a per-monitor proxy must be public too.
 */
final readonly class HttpCheckRunner implements CheckRunnerInterface
{
    public function __construct(
        private ?HttpClientInterface $httpClient,
        private MonitorUrlSsrfGuard $ssrfGuard,
        private bool $blockPrivateUrls = true,
        private ClockInterface $clock = new NativeClock(),
        private SocketConnectorInterface $connector = new StreamSocketConnector(),
    ) {
    }

    public function supports(Monitor $monitor): bool
    {
        return in_array($monitor->getType(), [MonitorType::Http, MonitorType::Https], true);
    }

    public function run(Monitor $monitor): CheckResultDto
    {
        $settings      = MonitorSettings::from($monitor);
        $config        = $monitor->getConfig();
        $url           = isset($config['url']) && is_string($config['url']) ? $config['url'] : $monitor->getTarget();
        $method        = isset($config['method']) && is_string($config['method']) ? strtoupper($config['method']) : 'GET';
        $timeout       = $settings->getRequestTimeoutSeconds();
        $expectedCodes = $settings->getExpectedStatusCodes();

        if ($this->blockPrivateUrls && $this->ssrfGuard->isBlocked($url)) {
            return new CheckResultDto(
                CheckStatus::Down,
                0,
                null,
                'Monitor URL targets a private or local network address (blocked).',
            );
        }

        $proxy = $settings->getProxyUrl();
        if ($this->blockPrivateUrls && $proxy !== null && $this->ssrfGuard->isBlocked($proxy)) {
            return new CheckResultDto(
                CheckStatus::Down,
                0,
                null,
                'Monitor proxy targets a private or local network address (blocked).',
            );
        }

        $client = $this->httpClient ?? HttpClient::create();
        if ($this->blockPrivateUrls) {
            $client = new NoPrivateNetworkHttpClient($client, MonitorUrlSsrfGuard::BLOCKED_SUBNETS);
        }
        $start = hrtime(true);

        try {
            $options = [
                'timeout'       => $timeout,
                'max_duration'  => $timeout + 2,
                'max_redirects' => $settings->getMaxRedirects(),
            ];

            $headers = $settings->getHeaders();
            $body    = $settings->getHttpBody();
            if ($body !== null) {
                $options['body'] = $body;
                $headers         = $this->applyBodyEncodingHeaders($headers, $settings->getBodyEncoding());
            }

            if ($headers !== []) {
                $options['headers'] = $headers;
            }

            if ($settings->isIgnoreTlsErrors()) {
                $options['verify_peer'] = false;
                $options['verify_host'] = false;
            }

            if ($proxy !== null) {
                $options['proxy'] = $proxy;
            }

            if ($settings->getAuthMethod() === 'basic' && $settings->getAuthUsername() !== '') {
                $options['auth_basic'] = [$settings->getAuthUsername(), $settings->getAuthPassword()];
            }

            $response    = $client->request($method, $url, $options);
            $statusCode  = $response->getStatusCode();
            $latencyMs   = (int) ((hrtime(true) - $start) / 1_000_000);
            $bodyContent = $response->getContent(false);

            if (!StatusCodeMatcher::matches($statusCode, $expectedCodes)) {
                return new CheckResultDto(
                    CheckStatus::Down,
                    $latencyMs,
                    $statusCode,
                    sprintf('Unexpected status code %d (expected %s)', $statusCode, $this->formatExpectedCodes($expectedCodes)),
                );
            }

            if (isset($config['keyword']) && is_string($config['keyword']) && $config['keyword'] !== '' && !str_contains($bodyContent, $config['keyword'])) {
                return new CheckResultDto(
                    CheckStatus::Down,
                    $latencyMs,
                    $statusCode,
                    'Response body does not contain expected keyword',
                );
            }

            if ($settings->isCheckCertExpiry() && $monitor->getType() === MonitorType::Https) {
                $certMessage = $this->checkCertificateExpiry($url);
                if ($certMessage !== null) {
                    return new CheckResultDto(CheckStatus::Degraded, $latencyMs, $statusCode, $certMessage);
                }
            }

            return new CheckResultDto(CheckStatus::Up, $latencyMs, $statusCode);
        } catch (TransportExceptionInterface $e) {
            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

            return new CheckResultDto(
                CheckStatus::Down,
                $latencyMs,
                null,
                $e->getMessage(),
            );
        }
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function applyBodyEncodingHeaders(array $headers, string $encoding): array
    {
        $lower = array_change_key_case($headers, CASE_LOWER);
        if (isset($lower['content-type'])) {
            return $headers;
        }

        $contentType = match ($encoding) {
            'xml'   => 'application/xml',
            'json'  => 'application/json',
            default => 'text/plain',
        };

        $headers['Content-Type'] = $contentType;

        return $headers;
    }

    private function checkCertificateExpiry(string $url): ?string
    {
        // The request above already succeeded, so the URL has a host.
        $host       = (string) parse_url($url, PHP_URL_HOST);
        $port       = (int) (parse_url($url, PHP_URL_PORT) ?? 443);
        $connection = $this->connector->connect(sprintf('ssl://%s:%d', $host, $port), 5.0, [
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ],
        ]);

        $cert   = $connection->peerCertificate;
        $parsed = $cert instanceof OpenSSLCertificate ? openssl_x509_parse($cert) : false;
        if (!is_array($parsed) || !is_int($parsed['validTo_time_t'] ?? null)) {
            return null;
        }

        $daysLeft = (int) floor(($parsed['validTo_time_t'] - $this->clock->now()->getTimestamp()) / 86400);
        if ($daysLeft < 14) {
            return sprintf('TLS certificate expires in %d day(s)', max(0, $daysLeft));
        }

        return null;
    }

    /**
     * @param list<int> $codes
     */
    private function formatExpectedCodes(array $codes): string
    {
        if (count($codes) <= 8) {
            return implode(',', $codes);
        }

        return sprintf('%d codes', count($codes));
    }
}
