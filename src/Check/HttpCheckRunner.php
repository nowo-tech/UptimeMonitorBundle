<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Monitor\MonitorSettings;
use Nowo\UptimeMonitorBundle\Monitor\StatusCodeMatcher;
use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function count;
use function in_array;
use function is_int;
use function is_string;
use function sprintf;

use const CASE_LOWER;
use const PHP_URL_HOST;
use const PHP_URL_PORT;
use const STREAM_CLIENT_CONNECT;

/**
 * Executes HTTP/HTTPS monitor checks via Symfony HttpClient.
 */
final class HttpCheckRunner implements CheckRunnerInterface
{
    public function __construct(
        private readonly ?HttpClientInterface $httpClient,
        private readonly MonitorUrlSsrfGuard $ssrfGuard,
        private readonly bool $blockPrivateUrls = true,
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

        $client = $this->httpClient ?? HttpClient::create();
        $start  = hrtime(true);

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

            $proxy = $settings->getProxyUrl();
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

            if (isset($config['keyword']) && is_string($config['keyword']) && $config['keyword'] !== '') {
                if (!str_contains($bodyContent, $config['keyword'])) {
                    return new CheckResultDto(
                        CheckStatus::Down,
                        $latencyMs,
                        $statusCode,
                        'Response body does not contain expected keyword',
                    );
                }
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
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $port    = (int) (parse_url($url, PHP_URL_PORT) ?? 443);
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ],
        ]);

        $client = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return null;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert === false || $cert === null) {
            return null;
        }

        $parsed    = openssl_x509_parse($cert);
        $expiresAt = $parsed['validTo_time_t'] ?? null;
        if (!is_int($expiresAt)) {
            return null;
        }

        $daysLeft = (int) floor(($expiresAt - time()) / 86400);
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
