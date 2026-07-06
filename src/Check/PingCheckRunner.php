<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;

use function is_string;
use function sprintf;

use const PHP_OS_FAMILY;

/**
 * ICMP ping check via system ping binary (OS-dependent; requires ping in PATH).
 */
final class PingCheckRunner implements CheckRunnerInterface
{
    public function supports(Monitor $monitor): bool
    {
        return $monitor->getType() === MonitorType::Ping;
    }

    public function run(Monitor $monitor): CheckResultDto
    {
        $config  = $monitor->getConfig();
        $host    = isset($config['host']) && is_string($config['host']) ? $config['host'] : $monitor->getTarget();
        $timeout = isset($config['timeout']) && is_numeric($config['timeout']) ? (float) $config['timeout'] : 5.0;

        if (!$this->isValidHost($host)) {
            return new CheckResultDto(CheckStatus::Unknown, 0, null, 'Invalid ping host');
        }

        $command = $this->buildPingCommand($host, $timeout);
        if ($command === null) {
            return new CheckResultDto(
                CheckStatus::Unknown,
                0,
                null,
                'ICMP ping is not supported on this operating system',
            );
        }

        $start    = hrtime(true);
        $output   = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);

        if ($exitCode !== 0) {
            $message = $output !== [] ? implode("\n", $output) : sprintf('Ping failed (exit code %d)', $exitCode);

            return new CheckResultDto(CheckStatus::Down, $elapsedMs, null, $message);
        }

        $latencyMs = $this->parseLatencyMs($output) ?? $elapsedMs;

        return new CheckResultDto(CheckStatus::Up, $latencyMs);
    }

    private function isValidHost(string $host): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9.\-:]+$/', $host);
    }

    private function buildPingCommand(string $host, float $timeoutSeconds): ?string
    {
        $timeoutSeconds = max(1.0, min(30.0, $timeoutSeconds));
        $escapedHost    = escapeshellarg($host);

        return match (PHP_OS_FAMILY) {
            'Linux' => sprintf(
                'ping -c 1 -W %d %s',
                (int) ceil($timeoutSeconds),
                $escapedHost,
            ),
            'Darwin' => sprintf(
                'ping -c 1 -t %d %s',
                (int) ceil($timeoutSeconds),
                $escapedHost,
            ),
            'BSD' => sprintf(
                'ping -c 1 -W %d %s',
                (int) ceil($timeoutSeconds * 1000),
                $escapedHost,
            ),
            default => null,
        };
    }

    /**
     * @param list<string> $output
     */
    private function parseLatencyMs(array $output): ?int
    {
        $text = implode("\n", $output);

        if (preg_match('/time[=<](\d+(?:\.\d+)?)\s*ms/i', $text, $matches) === 1) {
            return (int) round((float) $matches[1]);
        }

        return null;
    }
}
