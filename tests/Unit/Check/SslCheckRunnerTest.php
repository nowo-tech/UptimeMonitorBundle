<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\SslCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Check\SslCheckRunner
 */
final class SslCheckRunnerTest extends TestCase
{
    public function testSupportsSslType(): void
    {
        $runner  = new SslCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'SSL', MonitorType::Ssl, 'example.com');
        $monitor->setConfig(['host' => 'example.com', 'port' => 443]);

        self::assertTrue($runner->supports($monitor));
    }

    public function testRunReturnsDownWhenHandshakeFails(): void
    {
        $runner  = new SslCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'SSL fail', MonitorType::Ssl, '127.0.0.1');
        $monitor->setConfig(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 1.0]);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
    }

    public function testRunReturnsUpOrDegradedForValidCertificate(): void
    {
        if (@fsockopen('ssl://example.com', 443, $errno, $errstr, 3) === false) {
            self::markTestSkipped('SSL endpoint unreachable in this environment');
        }

        $runner  = new SslCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'SSL ok', MonitorType::Ssl, 'example.com');
        $monitor->setConfig(['host' => 'example.com', 'port' => 443, 'days_before_expiry' => 0]);

        $result = $runner->run($monitor);

        self::assertContains(
            $result->status,
            [CheckStatus::Up, CheckStatus::Degraded],
            'Valid cert should be up or degraded when near expiry threshold',
        );
        self::assertArrayHasKey('days_left', $result->metadata ?? []);
    }
}
