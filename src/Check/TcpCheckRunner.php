<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;

use function is_string;
use function sprintf;

/**
 * TCP port connectivity check via stream_socket_client.
 */
final class TcpCheckRunner implements CheckRunnerInterface
{
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

        $start  = hrtime(true);
        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
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
