<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Nowo\UptimeMonitorBundle\Check\Network\SocketConnectorInterface;
use Nowo\UptimeMonitorBundle\Check\Network\StreamSocketConnector;
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
    /**
     * Creates the TCP runner; the defaults block private targets and use real sockets.
     *
     * @param MonitorUrlSsrfGuard $ssrfGuard Validates targets against private and local networks
     * @param bool $blockPrivateUrls Whether private / local targets are refused (`checks.block_private_urls`)
     * @param SocketConnectorInterface $connector Opens the TCP connection
     */
    public function __construct(
        private MonitorUrlSsrfGuard $ssrfGuard = new MonitorUrlSsrfGuard(),
        private bool $blockPrivateUrls = true,
        private SocketConnectorInterface $connector = new StreamSocketConnector(),
    ) {
    }

    /**
     * Whether the monitor is a TCP port check.
     */
    public function supports(Monitor $monitor): bool
    {
        return $monitor->getType() === MonitorType::Tcp;
    }

    /**
     * Connects to the monitor host and port: up when the connection opens, down otherwise.
     */
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

        $start      = hrtime(true);
        $connection = $this->connector->connect(sprintf('tcp://%s:%d', $connectHost, $port), $timeout);
        $latencyMs  = (int) ((hrtime(true) - $start) / 1_000_000);

        if (!$connection->connected) {
            return new CheckResultDto(
                CheckStatus::Down,
                $latencyMs,
                null,
                $connection->errorMessage !== ''
                    ? $connection->errorMessage
                    : sprintf('TCP connection failed (errno %d)', $connection->errorCode),
            );
        }

        return new CheckResultDto(CheckStatus::Up, $latencyMs);
    }
}
