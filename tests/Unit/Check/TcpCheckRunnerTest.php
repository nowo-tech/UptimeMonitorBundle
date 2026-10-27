<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\Network\SocketConnection;
use Nowo\UptimeMonitorBundle\Check\TcpCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Tests\Support\FakeSocketConnector;
use Nowo\UptimeMonitorBundle\Tests\Support\OfflineDns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TcpCheckRunner::class)]
final class TcpCheckRunnerTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function monitor(array $config, MonitorType $type = MonitorType::Tcp): Monitor
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'TCP', $type, 'db.example:5432');
        $monitor->setConfig($config);

        return $monitor;
    }

    public function testSupportsOnlyTcp(): void
    {
        $runner = new TcpCheckRunner();

        self::assertTrue($runner->supports($this->monitor([])));
        self::assertFalse($runner->supports($this->monitor([], MonitorType::Http)));
    }

    public function testUpConnectsToTheResolvedAddressWithDefaults(): void
    {
        $connector = new FakeSocketConnector(new SocketConnection(true));
        $runner    = new TcpCheckRunner(OfflineDns::guard(['db.example:5432' => ['93.184.216.5']]), true, $connector);

        $result = $runner->run($this->monitor([]));

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame('tcp://93.184.216.5:443', $connector->calls[0]['remote']);
        self::assertSame(10.0, $connector->calls[0]['timeout']);
    }

    public function testIpv6AddressIsBracketed(): void
    {
        $connector = new FakeSocketConnector(new SocketConnection(true));
        $runner    = new TcpCheckRunner(OfflineDns::guard([], ['db.example' => ['2606:4700::5']]), true, $connector);

        $runner->run($this->monitor(['host' => 'db.example', 'port' => 5432, 'timeout' => 2]));

        self::assertSame('tcp://[2606:4700::5]:5432', $connector->calls[0]['remote']);
        self::assertSame(2.0, $connector->calls[0]['timeout']);
    }

    public function testDownReportsTheConnectionError(): void
    {
        $runner = new TcpCheckRunner(OfflineDns::guard(), false, new FakeSocketConnector(new SocketConnection(false, 111, 'Connection refused')));

        $result = $runner->run($this->monitor(['host' => '127.0.0.1', 'port' => 1]));

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertSame('Connection refused', $result->message);
    }

    public function testDownWithoutErrorMessageReportsErrno(): void
    {
        $runner = new TcpCheckRunner(OfflineDns::guard(), false, new FakeSocketConnector(new SocketConnection(false, 110)));

        self::assertSame('TCP connection failed (errno 110)', $runner->run($this->monitor(['host' => '127.0.0.1']))->message);
    }

    public function testPrivateTargetsAreBlocked(): void
    {
        $connector = new FakeSocketConnector(new SocketConnection(true));
        $runner    = new TcpCheckRunner(OfflineDns::guard(), true, $connector);

        foreach (['127.0.0.1', '[::ffff:127.0.0.1]', 'localhost'] as $host) {
            $result = $runner->run($this->monitor(['host' => $host, 'port' => 6379]));
            self::assertSame(CheckStatus::Down, $result->status);
            self::assertStringContainsString('private or local', (string) $result->message);
        }

        self::assertSame([], $connector->calls);
    }

    public function testUnresolvableHostIsDown(): void
    {
        $runner = new TcpCheckRunner(OfflineDns::guard(), true, new FakeSocketConnector(new SocketConnection(true)));

        $result = $runner->run($this->monitor(['host' => 'does-not-exist.invalid']));

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertSame('Could not resolve host does-not-exist.invalid', $result->message);
    }

    public function testPrivateTargetsAreAllowedWhenBlockingIsDisabled(): void
    {
        $connector = new FakeSocketConnector(new SocketConnection(true));
        $runner    = new TcpCheckRunner(OfflineDns::guard(), false, $connector);

        self::assertSame(CheckStatus::Up, $runner->run($this->monitor(['host' => 'localhost', 'port' => 6379]))->status);
        self::assertSame('tcp://localhost:6379', $connector->calls[0]['remote']);
    }
}
