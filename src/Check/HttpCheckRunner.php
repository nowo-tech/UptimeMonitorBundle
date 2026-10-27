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
use OpenSSLCertificate;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function count;
use function filter_var;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function parse_url;
use function sprintf;
use function str_contains;
use function strlen;
use function substr;
use function trim;

use const CASE_LOWER;
use const FILTER_VALIDATE_IP;

/**
 * Executes HTTP/HTTPS monitor checks via Symfony HttpClient.
 *
 * When private targets are blocked, the client is wrapped in {@see NoPrivateNetworkHttpClient} so every redirect hop
 * and the actually connected IP are re-validated (DNS rebinding), and a per-monitor proxy is resolved once, validated
 * and pinned to that IP. The certificate-expiry probe reads the chain of the same (validated) connection.
 *
 * {@see CheckLimits} caps the timeout and redirects; the body is read only for a keyword, at most `max_body_bytes`.
 */
final readonly class HttpCheckRunner implements CheckRunnerInterface
{
    /**
     * Creates the HTTP runner; the defaults block private targets and use the system clock and real sockets.
     *
     * @param HttpClientInterface|null $httpClient HTTP client (a default client is created when null)
     * @param MonitorUrlSsrfGuard $ssrfGuard Validates targets against private and local networks
     * @param bool $blockPrivateUrls Whether private / local targets are refused (`checks.block_private_urls`)
     * @param ClockInterface $clock Clock for the certificate expiry check
     * @param CheckLimits $limits Global caps (timeout, redirects, body bytes) applied over the monitor settings
     */
    public function __construct(
        private ?HttpClientInterface $httpClient,
        private MonitorUrlSsrfGuard $ssrfGuard,
        private bool $blockPrivateUrls = true,
        private ClockInterface $clock = new NativeClock(),
        private CheckLimits $limits = new CheckLimits(),
    ) {
    }

    /**
     * Whether the monitor is an HTTP or HTTPS check.
     */
    public function supports(Monitor $monitor): bool
    {
        return in_array($monitor->getType(), [MonitorType::Http, MonitorType::Https], true);
    }

    /**
     * Sends the configured request and checks the status code, the keyword and, for HTTPS, the certificate expiry.
     */
    public function run(Monitor $monitor): CheckResultDto
    {
        $settings      = MonitorSettings::from($monitor);
        $config        = $monitor->getConfig();
        $url           = isset($config['url']) && is_string($config['url']) ? $config['url'] : $monitor->getTarget();
        $method        = isset($config['method']) && is_string($config['method']) ? strtoupper($config['method']) : 'GET';
        $timeout       = $this->limits->clampTimeout($settings->getRequestTimeoutSeconds());
        $keyword       = isset($config['keyword']) && is_string($config['keyword']) ? $config['keyword'] : '';
        $certCheck     = $settings->isCheckCertExpiry() && $monitor->getType() === MonitorType::Https;
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
        if ($this->blockPrivateUrls && $proxy !== null) {
            $proxy = $this->pinProxy($proxy);
            if ($proxy === null) {
                return new CheckResultDto(
                    CheckStatus::Down,
                    0,
                    null,
                    'Monitor proxy targets a private or local network address (blocked).',
                );
            }
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
                'max_redirects' => $this->limits->clampRedirects($settings->getMaxRedirects()),
            ];

            if ($certCheck) {
                // Certificate of the connection that was just validated (pinned IP, proxy honoured) — no second lookup.
                $options['capture_peer_cert_chain'] = true;
            }

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

            $response   = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $latencyMs  = (int) ((hrtime(true) - $start) / 1_000_000);
            $chain      = $certCheck ? $response->getInfo('peer_certificate_chain') : null;
            // Never buffer a body nobody reads: only a keyword check needs it, and only its first max_body_bytes.
            $bodyContent = $keyword !== '' ? $this->readBodyPrefix($client, $response) : '';
            $response->cancel();

            if (!StatusCodeMatcher::matches($statusCode, $expectedCodes)) {
                return new CheckResultDto(
                    CheckStatus::Down,
                    $latencyMs,
                    $statusCode,
                    sprintf('Unexpected status code %d (expected %s)', $statusCode, $this->formatExpectedCodes($expectedCodes)),
                );
            }

            if ($keyword !== '' && !str_contains($bodyContent, $keyword)) {
                return new CheckResultDto(
                    CheckStatus::Down,
                    $latencyMs,
                    $statusCode,
                    'Response body does not contain expected keyword',
                );
            }

            if ($certCheck) {
                $certMessage = $this->checkCertificateExpiry($chain);
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

    /**
     * Resolves the proxy host once, rejects private/local answers and returns the proxy URL pinned to the validated IP
     * (null when blocked or unresolvable), so curl cannot re-resolve it to another address (DNS rebinding).
     */
    private function pinProxy(string $proxy): ?string
    {
        $parts = parse_url($proxy);
        $host  = is_array($parts) ? trim($parts['host'] ?? '', '[]') : '';
        if ($host === '') {
            return null;
        }

        $addresses = $this->ssrfGuard->resolveAddresses($host);
        if ($addresses === [] || $this->ssrfGuard->isBlockedResolution($host, $addresses)) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $proxy;
        }

        $ip       = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
        $userInfo = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '';

        return ($parts['scheme'] ?? 'http') . '://' . $userInfo . $ip . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Reads at most `max_body_bytes` of the response body, then cancels the transfer.
     */
    private function readBodyPrefix(HttpClientInterface $client, ResponseInterface $response): string
    {
        $max  = $this->limits->maxBodyBytes;
        $body = '';
        foreach ($client->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (strlen($body) >= $max) {
                return substr($body, 0, $max);
            }
        }

        return $body;
    }

    private function checkCertificateExpiry(mixed $chain): ?string
    {
        $cert   = is_array($chain) ? ($chain[0] ?? null) : null;
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
