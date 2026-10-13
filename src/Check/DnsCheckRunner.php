<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Closure;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;

use function dns_get_record;
use function filter_var;
use function is_string;
use function sprintf;
use function str_contains;
use function strtoupper;
use function trim;

use const DNS_A;
use const DNS_AAAA;
use const DNS_CNAME;
use const DNS_MX;
use const DNS_TXT;
use const FILTER_VALIDATE_IP;

/**
 * DNS resolution check using PHP dns_get_record.
 *
 * When private targets are blocked (`checks.block_private_urls`), the hostname is validated with
 * {@see MonitorUrlSsrfGuard} before the lookup (private IP literals, `localhost`, metadata hosts) and an answer
 * that contains a private/local address (A/AAAA) or a blocked target name (CNAME/MX) is reported as blocked
 * without exposing the resolved records, so DNS monitors cannot be used to map internal (split-horizon)
 * resolution or probe for DNS-rebinding names.
 */
final readonly class DnsCheckRunner implements CheckRunnerInterface
{
    private const BLOCKED_MESSAGE = 'DNS hostname targets or resolves to a private or local network address (blocked).';

    /** @var Closure(string, int): (array<int, array<string, mixed>>|false) */
    private Closure $lookup;

    /**
     * @param (Closure(string, int): (array<int, array<string, mixed>>|false))|null $lookup DNS lookup (defaults to dns_get_record)
     */
    public function __construct(
        private MonitorUrlSsrfGuard $ssrfGuard = new MonitorUrlSsrfGuard(),
        private bool $blockPrivateUrls = true,
        ?Closure $lookup = null,
    ) {
        $this->lookup = $lookup ?? static fn (string $hostname, int $type): array|false => @dns_get_record($hostname, $type);
    }

    public function supports(Monitor $monitor): bool
    {
        return $monitor->getType() === MonitorType::Dns;
    }

    public function run(Monitor $monitor): CheckResultDto
    {
        $config   = $monitor->getConfig();
        $hostname = isset($config['hostname']) && is_string($config['hostname'])
            ? $config['hostname']
            : $monitor->getTarget();
        $recordType = isset($config['record_type']) && is_string($config['record_type'])
            ? strtoupper($config['record_type'])
            : 'A';
        $expected = isset($config['expected_value']) && is_string($config['expected_value'])
            ? $config['expected_value']
            : null;

        if ($this->blockPrivateUrls && $this->isBlockedHostname($hostname)) {
            return new CheckResultDto(CheckStatus::Down, 0, null, self::BLOCKED_MESSAGE);
        }

        $start = hrtime(true);
        $flag  = match ($recordType) {
            'AAAA'  => DNS_AAAA,
            'CNAME' => DNS_CNAME,
            'MX'    => DNS_MX,
            'TXT'   => DNS_TXT,
            default => DNS_A,
        };

        $records   = ($this->lookup)($hostname, $flag);
        $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

        if ($records === false || $records === []) {
            return new CheckResultDto(
                CheckStatus::Down,
                $latencyMs,
                null,
                sprintf('No %s records for %s', $recordType, $hostname),
            );
        }

        if ($this->blockPrivateUrls && $this->hasBlockedRecord($records)) {
            return new CheckResultDto(CheckStatus::Down, $latencyMs, null, self::BLOCKED_MESSAGE);
        }

        if ($expected !== null && $expected !== '') {
            $found = false;
            foreach ($records as $record) {
                $value = $record['ip'] ?? $record['ipv6'] ?? $record['target'] ?? $record['txt'] ?? null;
                if (is_string($value) && str_contains($value, $expected)) {
                    $found = true;
                    break;
                }
                if (isset($record['ip']) && $record['ip'] === $expected) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return new CheckResultDto(
                    CheckStatus::Down,
                    $latencyMs,
                    null,
                    sprintf('Expected value "%s" not found in DNS response', $expected),
                    ['records' => $records],
                );
            }
        }

        return new CheckResultDto(
            CheckStatus::Up,
            $latencyMs,
            null,
            null,
            ['records' => $records],
        );
    }

    /**
     * Blocked names (localhost, metadata hosts) and private/local IP literals.
     */
    private function isBlockedHostname(string $hostname): bool
    {
        $literal = trim($hostname, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false && $this->ssrfGuard->isBlockedIp($literal)) {
            return true;
        }

        return $this->ssrfGuard->isBlockedResolution($hostname, []);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function hasBlockedRecord(array $records): bool
    {
        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && is_string($record[$key]) && $this->ssrfGuard->isBlockedIp($record[$key])) {
                    return true;
                }
            }
            if (isset($record['target']) && is_string($record['target']) && $this->isBlockedHostname($record['target'])) {
                return true;
            }
        }

        return false;
    }
}
