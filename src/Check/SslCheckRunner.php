<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;

use function is_object;
use function is_string;
use function sprintf;

use const STREAM_CLIENT_CONNECT;

/**
 * SSL certificate expiry check.
 */
final class SslCheckRunner implements CheckRunnerInterface
{
    public function supports(Monitor $monitor): bool
    {
        return $monitor->getType() === MonitorType::Ssl;
    }

    public function run(Monitor $monitor): CheckResultDto
    {
        $config  = $monitor->getConfig();
        $host    = isset($config['host']) && is_string($config['host']) ? $config['host'] : $monitor->getTarget();
        $port    = isset($config['port']) && is_numeric($config['port']) ? (int) $config['port'] : 443;
        $minDays = isset($config['days_before_expiry']) && is_numeric($config['days_before_expiry'])
            ? (int) $config['days_before_expiry']
            : 14;
        $timeout = isset($config['timeout']) && is_numeric($config['timeout']) ? (float) $config['timeout'] : 10.0;

        $start   = hrtime(true);
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => true,
                'verify_peer_name'  => true,
            ],
        ]);

        $errno  = 0;
        $errstr = '';
        $client = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

        if ($client === false) {
            return new CheckResultDto(
                CheckStatus::Down,
                $latencyMs,
                null,
                $errstr !== '' ? $errstr : sprintf('SSL handshake failed (errno %d)', $errno),
            );
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!is_string($cert) && !is_object($cert)) {
            return new CheckResultDto(CheckStatus::Unknown, $latencyMs, null, 'Could not capture peer certificate');
        }

        $certPem = '';
        if (!openssl_x509_export($cert, $certPem)) {
            return new CheckResultDto(CheckStatus::Unknown, $latencyMs, null, 'Could not export certificate');
        }

        $parsed = openssl_x509_parse($certPem);
        if ($parsed === false || !isset($parsed['validTo_time_t'])) {
            return new CheckResultDto(CheckStatus::Unknown, $latencyMs, null, 'Could not parse certificate');
        }

        $expiresAt = (new DateTimeImmutable())->setTimestamp((int) $parsed['validTo_time_t']);
        $daysLeft  = (int) floor(($expiresAt->getTimestamp() - time()) / 86400);

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
