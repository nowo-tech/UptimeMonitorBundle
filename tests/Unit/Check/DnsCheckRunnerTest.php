<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\DnsCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function strtolower;

use const DNS_A;
use const DNS_AAAA;
use const DNS_CNAME;
use const DNS_MX;
use const DNS_TXT;

#[CoversClass(DnsCheckRunner::class)]
final class DnsCheckRunnerTest extends TestCase
{
    public function testSupportsDnsType(): void
    {
        $runner  = new DnsCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'DNS', MonitorType::Dns, 'example.com');
        $monitor->setConfig(['hostname' => 'example.com', 'record_type' => 'A']);

        self::assertTrue($runner->supports($monitor));
    }

    public function testEmptyOrFailedLookupIsDown(): void
    {
        foreach ([false, []] as $records) {
            $result = $this->runnerReturning($records)->run($this->dnsMonitor('gone.example'));

            self::assertSame(CheckStatus::Down, $result->status);
            self::assertSame('No A records for gone.example', $result->message);
        }
    }

    public function testExpectedValueMissingIsDownWithRecords(): void
    {
        $records = [['host' => 'x.example', 'type' => 'A', 'ip' => '93.184.215.14'], ['host' => 'x.example', 'type' => 'TXT', 'txt' => 7]];

        $result = $this->runnerReturning($records)->run($this->dnsMonitor('x.example', 'A', '93.184.216.1'));

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertSame('Expected value "93.184.216.1" not found in DNS response', $result->message);
        self::assertSame(['records' => $records], $result->metadata);
    }

    public function testRecordTypesMapToDnsFlagsAndConfigFallsBackToTarget(): void
    {
        $expected = ['AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'SOA' => DNS_A];

        foreach ($expected as $type => $flag) {
            $calls  = [];
            $runner = $this->runnerReturning([['host' => 'x.example', 'type' => $type, 'target' => 'mail.example']], true, $calls);
            self::assertSame(CheckStatus::Up, $runner->run($this->dnsMonitor('x.example', strtolower($type)))->status);
            self::assertSame([['x.example', $flag]], $calls);
        }

        $calls   = [];
        $monitor = new Monitor(new Tenant('main', 'Main'), 'DNS', MonitorType::Dns, 'target.example');
        $this->runnerReturning([['host' => 'target.example', 'type' => 'A', 'ip' => '93.184.215.14']], true, $calls)->run($monitor);
        self::assertSame([['target.example', DNS_A]], $calls);
    }

    /**
     * @param array<int, array<string, mixed>>|false $records
     * @param list<array{string, int}> $calls
     *
     * @param-out list<array{string, int}> $calls
     */
    private function runnerReturning(array|false $records, bool $block = true, array &$calls = []): DnsCheckRunner
    {
        $calls = [];

        return new DnsCheckRunner(
            new MonitorUrlSsrfGuard(),
            $block,
            static function (string $hostname, int $type) use ($records, &$calls): array|false {
                $calls[] = [$hostname, $type];

                return $records;
            },
        );
    }

    private function dnsMonitor(string $hostname, string $recordType = 'A', ?string $expected = null): Monitor
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'DNS', MonitorType::Dns, $hostname);
        $config  = ['hostname' => $hostname, 'record_type' => $recordType];
        if ($expected !== null) {
            $config['expected_value'] = $expected;
        }
        $monitor->setConfig($config);

        return $monitor;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedHostnameProvider(): iterable
    {
        yield 'localhost' => ['localhost'];
        yield 'sub.localhost' => ['db.localhost.'];
        yield 'metadata' => ['metadata.google.internal'];
        yield 'private IPv4 literal' => ['10.0.0.1'];
        yield 'loopback IPv6 literal' => ['[::1]'];
    }

    #[DataProvider('blockedHostnameProvider')]
    public function testBlockedHostnameIsRejectedBeforeLookup(string $hostname): void
    {
        $calls  = [];
        $runner = $this->runnerReturning([['ip' => '93.184.215.14']], true, $calls);

        $result = $runner->run($this->dnsMonitor($hostname));

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertStringContainsString('blocked', (string) $result->message);
        self::assertSame([], $calls);
        self::assertNull($result->metadata);
    }

    /**
     * @return iterable<string, array{string, array<int, array<string, mixed>>}>
     */
    public static function privateAnswerProvider(): iterable
    {
        yield 'A RFC1918' => ['A', [['host' => 'x.example', 'type' => 'A', 'ip' => '93.184.215.14'], ['host' => 'x.example', 'type' => 'A', 'ip' => '192.168.1.10']]];
        yield 'A loopback' => ['A', [['host' => 'x.example', 'type' => 'A', 'ip' => '127.0.0.1']]];
        yield 'A metadata' => ['A', [['host' => 'x.example', 'type' => 'A', 'ip' => '169.254.169.254']]];
        yield 'AAAA ULA' => ['AAAA', [['host' => 'x.example', 'type' => 'AAAA', 'ipv6' => 'fd00::1']]];
        yield 'CNAME localhost' => ['CNAME', [['host' => 'x.example', 'type' => 'CNAME', 'target' => 'localhost']]];
        yield 'MX private literal' => ['MX', [['host' => 'x.example', 'type' => 'MX', 'target' => '10.1.2.3']]];
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    #[DataProvider('privateAnswerProvider')]
    public function testPrivateAnswerIsReportedBlockedWithoutRecords(string $type, array $records): void
    {
        $runner = $this->runnerReturning($records);

        $result = $runner->run($this->dnsMonitor('x.example', $type, '10.'));

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertStringContainsString('blocked', (string) $result->message);
        self::assertNull($result->metadata);
    }

    public function testPublicAnswerIsUpWithRecords(): void
    {
        $records = [['host' => 'x.example', 'type' => 'A', 'ip' => '93.184.215.14']];
        $calls   = [];
        $runner  = $this->runnerReturning($records, true, $calls);

        $result = $runner->run($this->dnsMonitor('x.example', 'A', '93.184.215.14'));

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame(['records' => $records], $result->metadata);
        self::assertSame([['x.example', DNS_A]], $calls);
    }

    public function testPublicTxtAnswerIsUp(): void
    {
        $runner = $this->runnerReturning([['host' => 'x.example', 'type' => 'TXT', 'txt' => 'v=spf1 -all']]);

        self::assertSame(CheckStatus::Up, $runner->run($this->dnsMonitor('x.example', 'TXT', 'spf1'))->status);
    }

    public function testPrivateTargetsAllowedWhenBlockingDisabled(): void
    {
        $records = [['host' => 'db.corp', 'type' => 'A', 'ip' => '10.0.0.5']];
        $calls   = [];
        $runner  = $this->runnerReturning($records, false, $calls);

        $result = $runner->run($this->dnsMonitor('localhost', 'A', '10.0.0.5'));

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame(['records' => $records], $result->metadata);
        self::assertCount(1, $calls);
    }
}
