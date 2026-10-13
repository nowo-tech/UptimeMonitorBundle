<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\TcpCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Check\TcpCheckRunner
 */
final class TcpCheckRunnerTest extends TestCase
{
    public function testSupportsTcpType(): void
    {
        $runner  = new TcpCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'TCP', MonitorType::Tcp, 'example.com:443');
        $monitor->setConfig(['host' => 'example.com', 'port' => 443]);

        self::assertTrue($runner->supports($monitor));
    }

    public function testRunReturnsDownWhenPortClosed(): void
    {
        $runner  = new TcpCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'TCP fail', MonitorType::Tcp, '127.0.0.1:1');
        $monitor->setConfig(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 1.0]);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
    }

    public function testRunReturnsUpForOpenPort(): void
    {
        $runner  = new TcpCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'DNS port', MonitorType::Tcp, '8.8.8.8:53');
        $monitor->setConfig(['host' => '8.8.8.8', 'port' => 53, 'timeout' => 3.0]);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Up, $result->status);
    }

    public function testSupportsOnlyTcpType(): void
    {
        $runner  = new TcpCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'HTTP', MonitorType::Http, 'https://x.test');

        self::assertFalse($runner->supports($monitor));
    }

    public function testRunBlocksPrivateHostByDefault(): void
    {
        $runner  = new TcpCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'Private', MonitorType::Tcp, '127.0.0.1');
        $monitor->setConfig(['host' => '127.0.0.1', 'port' => 6379, 'timeout' => 1.0]);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertStringContainsString('private or local', (string) $result->message);
    }

    public function testRunBlocksIpv4MappedLoopback(): void
    {
        $runner  = new TcpCheckRunner(new MonitorUrlSsrfGuard(), true);
        $monitor = new Monitor(new Tenant('main', 'Main'), 'Mapped', MonitorType::Tcp, '::ffff:127.0.0.1');
        $monitor->setConfig(['host' => '[::ffff:127.0.0.1]', 'port' => 443, 'timeout' => 1.0]);

        self::assertStringContainsString('private or local', (string) $runner->run($monitor)->message);
    }

    public function testRunReturnsDownWhenHostUnresolvable(): void
    {
        $runner  = new TcpCheckRunner(new MonitorUrlSsrfGuard(), true);
        $monitor = new Monitor(new Tenant('main', 'Main'), 'Unresolvable', MonitorType::Tcp, 'does-not-exist.invalid');
        $monitor->setConfig(['host' => 'does-not-exist.invalid', 'port' => 443, 'timeout' => 1.0]);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertStringContainsString('Could not resolve host', (string) $result->message);
    }

    public function testRunConnectsToPinnedIpv6Address(): void
    {
        $runner  = new TcpCheckRunner(new MonitorUrlSsrfGuard(), true);
        $monitor = new Monitor(new Tenant('main', 'Main'), 'IPv6', MonitorType::Tcp, '2001:4860:4860::8888');
        $monitor->setConfig(['host' => '2001:4860:4860::8888', 'port' => 1, 'timeout' => 1.0]);

        $result = $runner->run($monitor);

        self::assertStringNotContainsString('private or local', (string) $result->message);
    }

    public function testRunAllowsPrivateHostWhenBlockDisabled(): void
    {
        $runner  = new TcpCheckRunner(new MonitorUrlSsrfGuard(), false);
        $monitor = new Monitor(new Tenant('main', 'Main'), 'Private', MonitorType::Tcp, '127.0.0.1');
        $monitor->setConfig(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 1.0]);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertStringNotContainsString('private or local', (string) $result->message);
    }
}
