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

use const DNS_A;

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

    public function testRunReturnsDownForUnresolvableHost(): void
    {
        $runner  = new DnsCheckRunner();
        $monitor = new Monitor(
            new Tenant('main', 'Main'),
            'DNS fail',
            MonitorType::Dns,
            'this-host-should-not-exist-uptime-monitor.invalid.',
        );
        $monitor->setConfig([
            'hostname'    => 'this-host-should-not-exist-uptime-monitor.invalid.',
            'record_type' => 'A',
        ]);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
    }

    public function testRunReturnsUpForKnownHost(): void
    {
        if (!gethostbyname('example.com') || gethostbyname('example.com') === 'example.com') {
            self::markTestSkipped('DNS resolution unavailable in this environment');
        }

        $runner  = new DnsCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'DNS ok', MonitorType::Dns, 'example.com');
        $monitor->setConfig(['hostname' => 'example.com', 'record_type' => 'A']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Up, $result->status);
    }

    public function testRunReturnsDownWhenExpectedValueMissing(): void
    {
        if (!gethostbyname('example.com') || gethostbyname('example.com') === 'example.com') {
            self::markTestSkipped('DNS resolution unavailable in this environment');
        }

        $runner  = new DnsCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'DNS', MonitorType::Dns, 'example.com');
        $monitor->setConfig([
            'hostname'       => 'example.com',
            'record_type'    => 'A',
            'expected_value' => '255.255.255.255',
        ]);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
    }

    public function testRunSupportsAlternateRecordTypes(): void
    {
        $runner  = new DnsCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'DNS', MonitorType::Dns, 'example.com');

        foreach (['AAAA', 'CNAME', 'MX', 'TXT'] as $type) {
            $monitor->setConfig(['hostname' => 'invalid.invalid.', 'record_type' => $type]);
            $result = $runner->run($monitor);
            self::assertSame(CheckStatus::Down, $result->status);
        }
    }

    /**
     * @param array<int, array<string, mixed>>|false $records
     * @param list<array{string, int}> $calls
     *
     * @param-out list<array{string, int}> $calls
     */
    private static function runnerReturning(array|false $records, bool $block = true, array &$calls = []): DnsCheckRunner
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

    private static function dnsMonitor(string $hostname, string $recordType = 'A', ?string $expected = null): Monitor
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
        $runner = self::runnerReturning([['ip' => '93.184.215.14']], true, $calls);

        $result = $runner->run(self::dnsMonitor($hostname));

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
        $runner = self::runnerReturning($records);

        $result = $runner->run(self::dnsMonitor('x.example', $type, '10.'));

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertStringContainsString('blocked', (string) $result->message);
        self::assertNull($result->metadata);
    }

    public function testPublicAnswerIsUpWithRecords(): void
    {
        $records = [['host' => 'x.example', 'type' => 'A', 'ip' => '93.184.215.14']];
        $calls   = [];
        $runner  = self::runnerReturning($records, true, $calls);

        $result = $runner->run(self::dnsMonitor('x.example', 'A', '93.184.215.14'));

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame(['records' => $records], $result->metadata);
        self::assertSame([['x.example', DNS_A]], $calls);
    }

    public function testPublicTxtAnswerIsUp(): void
    {
        $runner = self::runnerReturning([['host' => 'x.example', 'type' => 'TXT', 'txt' => 'v=spf1 -all']]);

        self::assertSame(CheckStatus::Up, $runner->run(self::dnsMonitor('x.example', 'TXT', 'spf1'))->status);
    }

    public function testPrivateTargetsAllowedWhenBlockingDisabled(): void
    {
        $records = [['host' => 'db.corp', 'type' => 'A', 'ip' => '10.0.0.5']];
        $calls   = [];
        $runner  = self::runnerReturning($records, false, $calls);

        $result = $runner->run(self::dnsMonitor('localhost', 'A', '10.0.0.5'));

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame(['records' => $records], $result->metadata);
        self::assertCount(1, $calls);
    }
}
