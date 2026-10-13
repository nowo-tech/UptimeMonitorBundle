<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;

use function is_string;
use function sprintf;
use function str_contains;

/**
 * TCP port connectivity check via stream_socket_client.
 *
 * When private targets are blocked, the host is resolved once, validated and the socket is opened to that
 * exact address (no re-resolution, DNS rebinding safe).
 */
final readonly class TcpCheckRunner implements CheckRunnerInterface
{
    public function __construct(
        private MonitorUrlSsrfGuard $ssrfGuard = new MonitorUrlSsrfGuard(),
        private bool $blockPrivateUrls = true,
    ) {
    }

    public function supports(Monitor $monitor): bool
    {
        return $monitor->getType() === MonitorType::Tcp;
    }

    public function run(Monitor $monitor): CheckResultDto
    {
        $config  = $monitor->getConfig();
        $host    = isset($config['host']) && is_string($config['host']) ? $config['host'] : $monitor->getTarget();
        $port    = isset($config['port']) && is_numeric($config['port']) ? (int) $config['port'] : 443;
        $timeout = isset($config['timeout']) && is_numeric($config['timeout']) ? (float) $config['timeout'] : 10.0;

        $connectHost = $host;
        if ($this->blockPrivateUrls) {
            $addresses = $this->ssrfGuard->resolveAddresses($host);
            if ($this->ssrfGuard->isBlockedResolution($host, $addresses)) {
                return new CheckResultDto(
                    CheckStatus::Down,
                    0,
                    null,
                    'TCP host targets a private or local network address (blocked).',
                );
            }
            if ($addresses === []) {
                return new CheckResultDto(CheckStatus::Down, 0, null, sprintf('Could not resolve host %s', $host));
            }
            $connectHost = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
        }

        $start  = hrtime(true);
        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $connectHost, $port),
            $errno,
            $errstr,
            $timeout,
        );

        $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

        if ($socket === false) {
            return new CheckResultDto(
                CheckStatus::Down,
                $latencyMs,
                null,
                $errstr !== '' ? $errstr : sprintf('TCP connection failed (errno %d)', $errno),
            );
        }

        fclose($socket);

        return new CheckResultDto(CheckStatus::Up, $latencyMs);
    }
}
