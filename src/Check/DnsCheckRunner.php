<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Check;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;

use function is_string;
use function sprintf;

use const DNS_A;
use const DNS_AAAA;
use const DNS_CNAME;
use const DNS_MX;
use const DNS_TXT;

/**
 * DNS resolution check using PHP dns_get_record.
 */
final class DnsCheckRunner implements CheckRunnerInterface
{
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

        $start = hrtime(true);
        $flag  = match ($recordType) {
            'AAAA'  => DNS_AAAA,
            'CNAME' => DNS_CNAME,
            'MX'    => DNS_MX,
            'TXT'   => DNS_TXT,
            default => DNS_A,
        };

        $records   = @dns_get_record($hostname, $flag);
        $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

        if ($records === false || $records === []) {
            return new CheckResultDto(
                CheckStatus::Down,
                $latencyMs,
                null,
                sprintf('No %s records for %s', $recordType, $hostname),
            );
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
}
