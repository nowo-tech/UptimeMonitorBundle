<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\TcpCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
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
}
