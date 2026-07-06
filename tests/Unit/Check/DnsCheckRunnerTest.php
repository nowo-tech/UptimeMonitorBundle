<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\DnsCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Check\DnsCheckRunner
 */
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
}
