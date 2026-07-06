<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\PingCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function in_array;

use const PHP_OS_FAMILY;

/**
 * @covers \Nowo\UptimeMonitorBundle\Check\PingCheckRunner
 */
final class PingCheckRunnerTest extends TestCase
{
    public function testSupportsPingType(): void
    {
        $runner  = new PingCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'Ping', MonitorType::Ping, '8.8.8.8');
        $monitor->setConfig(['host' => '8.8.8.8']);

        self::assertTrue($runner->supports($monitor));
    }

    public function testRunReturnsUnknownForInvalidHost(): void
    {
        $runner  = new PingCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'Ping', MonitorType::Ping, 'bad host');
        $monitor->setConfig(['host' => 'bad host']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Unknown, $result->status);
    }

    public function testRunReturnsDownForUnreachableHost(): void
    {
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin', 'BSD'], true)) {
            self::markTestSkipped('Ping runner only tested on Unix-like systems');
        }

        $runner  = new PingCheckRunner();
        $monitor = new Monitor(
            new Tenant('main', 'Main'),
            'Ping fail',
            MonitorType::Ping,
            '192.0.2.1',
        );
        $monitor->setConfig(['host' => '192.0.2.1', 'timeout' => 1.0]);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
    }

    public function testParseLatencyMsExtractsMilliseconds(): void
    {
        $runner = new PingCheckRunner();
        $method = new ReflectionMethod(PingCheckRunner::class, 'parseLatencyMs');
        $method->setAccessible(true);

        $latency = $method->invoke($runner, ['64 bytes from 8.8.8.8: time=12.5 ms']);

        self::assertSame(13, $latency);

        $latencyEq = $method->invoke($runner, ['reply from 8.8.8.8: bytes=64 time=9 ms']);
        self::assertSame(9, $latencyEq);
    }

    public function testParseLatencyMsReturnsNullWhenMissing(): void
    {
        $runner = new PingCheckRunner();
        $method = new ReflectionMethod(PingCheckRunner::class, 'parseLatencyMs');
        $method->setAccessible(true);

        self::assertNull($method->invoke($runner, ['no timing here']));
    }

    public function testBuildPingCommandForCurrentOsFamily(): void
    {
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin', 'BSD'], true)) {
            self::markTestSkipped('Ping command branches only on Unix-like systems');
        }

        $runner = new PingCheckRunner();
        $method = new ReflectionMethod(PingCheckRunner::class, 'buildPingCommand');
        $method->setAccessible(true);

        $command = $method->invoke($runner, '127.0.0.1', 2.5);

        self::assertNotNull($command);
        self::assertStringContainsString('127.0.0.1', $command);
        self::assertStringContainsString('ping', $command);
    }

    public function testRunUsesTargetWhenHostConfigMissing(): void
    {
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin', 'BSD'], true)) {
            self::markTestSkipped('Ping runner only tested on Unix-like systems');
        }

        $runner  = new PingCheckRunner();
        $monitor = new Monitor(new Tenant('main', 'Main'), 'Ping', MonitorType::Ping, '127.0.0.1');
        $monitor->setConfig(['timeout' => 1.0]);

        $result = $runner->run($monitor);

        self::assertContains($result->status, [CheckStatus::Up, CheckStatus::Down]);
    }
}
