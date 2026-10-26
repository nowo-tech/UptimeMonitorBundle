<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Closure;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;

use function is_string;
use function sprintf;

use const PHP_OS_FAMILY;

/**
 * ICMP ping check via system ping binary (OS-dependent; requires ping in PATH).
 */
final readonly class PingCheckRunner implements CheckRunnerInterface
{
    /** @var Closure(string): array{0: int, 1: list<string>} */
    private Closure $executor;

    /**
     * @param (Closure(string): array{0: int, 1: list<string>})|null $executor Runs the ping command and returns
     *                                                                         [exit code, output lines] (defaults to exec())
     * @param string $osFamily PHP_OS_FAMILY value used to build the command
     */
    public function __construct(
        private MonitorUrlSsrfGuard $ssrfGuard,
        private bool $blockPrivateUrls = true,
        ?Closure $executor = null,
        private string $osFamily = PHP_OS_FAMILY,
    ) {
        $this->executor = $executor ?? static function (string $command): array {
            $output   = [];
            $exitCode = 0;
            exec($command . ' 2>&1', $output, $exitCode);

            return [$exitCode, $output];
        };
    }

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

        if ($this->blockPrivateUrls) {
            $addresses = $this->ssrfGuard->resolveAddresses($host);
            if ($this->ssrfGuard->isBlockedResolution($host, $addresses)) {
                return new CheckResultDto(
                    CheckStatus::Unknown,
                    0,
                    null,
                    'Ping host targets a private or local network address (blocked)',
                );
            }
            if ($addresses === []) {
                // Like TCP/SSL: never fall back to the name (ping would re-resolve it → DNS rebinding).
                return new CheckResultDto(CheckStatus::Down, 0, null, sprintf('Could not resolve host %s', $host));
            }
            // Ping the validated address, not the name (no re-resolution between check and ping).
            $host = $addresses[0];
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

        $start               = hrtime(true);
        [$exitCode, $output] = ($this->executor)($command);
        $elapsedMs           = (int) ((hrtime(true) - $start) / 1_000_000);

        if ($exitCode !== 0) {
            $message = $output !== [] ? implode("\n", $output) : sprintf('Ping failed (exit code %d)', $exitCode);

            return new CheckResultDto(CheckStatus::Down, $elapsedMs, null, $message);
        }

        $latencyMs = $this->parseLatencyMs($output) ?? $elapsedMs;

        return new CheckResultDto(CheckStatus::Up, $latencyMs);
    }

    private function isValidHost(string $host): bool
    {
        // Leading "-" would be parsed by ping as an option (argument injection), even when shell-escaped.
        return (bool) preg_match('/^[a-zA-Z0-9.:][a-zA-Z0-9.\-:]*$/', $host);
    }

    private function buildPingCommand(string $host, float $timeoutSeconds): ?string
    {
        $timeoutSeconds = max(1.0, min(30.0, $timeoutSeconds));
        $escapedHost    = escapeshellarg($host);

        return match ($this->osFamily) {
            // -W: wait for the reply; -w: hard deadline for the whole run (incl. name resolution) — REQ-RUNTIME-001.
            'Linux' => sprintf(
                'ping -c 1 -W %1$d -w %1$d %2$s',
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
