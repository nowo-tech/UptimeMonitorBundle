<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use DateTimeInterface;
use Nowo\UptimeMonitorBundle\Check\Network\SocketConnectorInterface;
use Nowo\UptimeMonitorBundle\Check\Network\StreamSocketConnector;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;
use OpenSSLCertificate;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function is_array;
use function is_string;
use function sprintf;
use function str_contains;
use function trim;

/**
 * SSL certificate expiry check.
 *
 * When private targets are blocked, the host is resolved once, validated and the TLS socket is opened to that
 * exact address with peer_name set to the original host (SNI + name verification, DNS rebinding safe).
 */
final readonly class SslCheckRunner implements CheckRunnerInterface
{
    /**
     * Creates the SSL runner; the defaults block private targets and use the system clock and real sockets.
     *
     * @param MonitorUrlSsrfGuard $ssrfGuard Validates targets against private and local networks
     * @param bool $blockPrivateUrls Whether private / local targets are refused (`checks.block_private_urls`)
     * @param ClockInterface $clock Clock for the days left until expiry
     * @param SocketConnectorInterface $connector Opens the TLS connection
     */
    public function __construct(
        private MonitorUrlSsrfGuard $ssrfGuard = new MonitorUrlSsrfGuard(),
        private bool $blockPrivateUrls = true,
        private ClockInterface $clock = new NativeClock(),
        private SocketConnectorInterface $connector = new StreamSocketConnector(),
    ) {
    }

    /**
     * Whether the monitor is an SSL certificate check.
     */
    public function supports(Monitor $monitor): bool
    {
        return $monitor->getType() === MonitorType::Ssl;
    }

    /**
     * Opens a verified TLS connection and checks the peer certificate expiry: down when expired or unreachable,
     * degraded within `days_before_expiry`, up otherwise.
     */
    public function run(Monitor $monitor): CheckResultDto
    {
        $config  = $monitor->getConfig();
        $host    = isset($config['host']) && is_string($config['host']) ? $config['host'] : $monitor->getTarget();
        $port    = isset($config['port']) && is_numeric($config['port']) ? (int) $config['port'] : 443;
        $minDays = isset($config['days_before_expiry']) && is_numeric($config['days_before_expiry'])
            ? (int) $config['days_before_expiry']
            : 14;
        $timeout = isset($config['timeout']) && is_numeric($config['timeout']) ? (float) $config['timeout'] : 10.0;

        $connectHost = $host;
        if ($this->blockPrivateUrls) {
            $addresses = $this->ssrfGuard->resolveAddresses($host);
            if ($this->ssrfGuard->isBlockedResolution($host, $addresses)) {
                return new CheckResultDto(
                    CheckStatus::Down,
                    0,
                    null,
                    'SSL host targets a private or local network address (blocked).',
                );
            }
            if ($addresses === []) {
                return new CheckResultDto(CheckStatus::Down, 0, null, sprintf('Could not resolve host %s', $host));
            }
            $connectHost = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
        }

        $start      = hrtime(true);
        $connection = $this->connector->connect(sprintf('ssl://%s:%d', $connectHost, $port), $timeout, [
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'peer_name'         => trim($host, '[]'),
            ],
        ]);
        $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

        if (!$connection->connected) {
            return new CheckResultDto(
                CheckStatus::Down,
                $latencyMs,
                null,
                $connection->errorMessage !== ''
                    ? $connection->errorMessage
                    : sprintf('SSL handshake failed (errno %d)', $connection->errorCode),
            );
        }

        $cert = $connection->peerCertificate;
        if (!is_string($cert) && !$cert instanceof OpenSSLCertificate) {
            return new CheckResultDto(CheckStatus::Unknown, $latencyMs, null, 'Could not capture peer certificate');
        }

        $parsed = @openssl_x509_parse($cert);
        if (!is_array($parsed) || !isset($parsed['validTo_time_t'])) {
            return new CheckResultDto(CheckStatus::Unknown, $latencyMs, null, 'Could not parse certificate');
        }

        $expiresAt = $this->clock->now()->setTimestamp((int) $parsed['validTo_time_t']);
        $daysLeft  = (int) floor(($expiresAt->getTimestamp() - $this->clock->now()->getTimestamp()) / 86400);

        $metadata = [
            'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
            'days_left'  => $daysLeft,
        ];

        if ($daysLeft < 0) {
            return new CheckResultDto(
                CheckStatus::Down,
                $latencyMs,
                null,
                'Certificate expired',
                $metadata,
            );
        }

        if ($daysLeft <= $minDays) {
            return new CheckResultDto(
                CheckStatus::Degraded,
                $latencyMs,
                null,
                sprintf('Certificate expires in %d day(s)', $daysLeft),
                $metadata,
            );
        }

        return new CheckResultDto(CheckStatus::Up, $latencyMs, null, null, $metadata);
    }
}
