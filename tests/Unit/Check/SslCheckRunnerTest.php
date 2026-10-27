<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\CheckLimits;
use Nowo\UptimeMonitorBundle\Check\Network\SocketConnection;
use Nowo\UptimeMonitorBundle\Check\SslCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Tests\Support\FakeSocketConnector;
use Nowo\UptimeMonitorBundle\Tests\Support\OfflineDns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(SslCheckRunner::class)]
final class SslCheckRunnerTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function monitor(array $config = [], MonitorType $type = MonitorType::Ssl): Monitor
    {
        $monitor = new Monitor(new Tenant('main', 'Main'), 'SSL', $type, 'secure.example');
        $monitor->setConfig($config);

        return $monitor;
    }

    /**
     * @param-out FakeSocketConnector $connector
     */
    private function runner(SocketConnection $connection, string $now = '2126-01-01 00:00:00', ?FakeSocketConnector &$connector = null): SslCheckRunner
    {
        $connector = new FakeSocketConnector($connection);

        return new SslCheckRunner(
            OfflineDns::guard(['secure.example' => ['93.184.216.7']], ['v6.example' => ['2606:4700::7']]),
            true,
            new MockClock($now . ' UTC'),
            $connector,
        );
    }

    public function testSupportsOnlySsl(): void
    {
        $runner = new SslCheckRunner();

        self::assertTrue($runner->supports($this->monitor()));
        self::assertFalse($runner->supports($this->monitor([], MonitorType::Tcp)));
    }

    public function testValidCertificateIsUpAndPinsTheResolvedAddress(): void
    {
        $runner = $this->runner(new SocketConnection(true, peerCertificate: FakeSocketConnector::fixtureCertificate()), connector: $connector);

        $result = $runner->run($this->monitor());

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame(274, $result->metadata['days_left'] ?? null);
        self::assertStringStartsWith('2126-10-02T19:33:06', (string) ($result->metadata['expires_at'] ?? ''));
        self::assertSame('ssl://93.184.216.7:443', $connector->calls[0]['remote']);
        self::assertSame(10.0, $connector->calls[0]['timeout']);
        self::assertSame('secure.example', $connector->calls[0]['options']['ssl']['peer_name']);
        self::assertTrue($connector->calls[0]['options']['ssl']['verify_peer']);
    }

    public function testCertificateNearExpiryIsDegraded(): void
    {
        $runner = $this->runner(new SocketConnection(true, peerCertificate: FakeSocketConnector::fixtureCertificate()), '2126-09-25 00:00:00', $connector);

        $result = $runner->run($this->monitor(['host' => 'v6.example', 'port' => 8443, 'days_before_expiry' => 10, 'timeout' => 3]));

        self::assertSame(CheckStatus::Degraded, $result->status);
        self::assertSame('Certificate expires in 7 day(s)', $result->message);
        self::assertSame('ssl://[2606:4700::7]:8443', $connector->calls[0]['remote']);
    }

    public function testExpiredCertificateIsDown(): void
    {
        $runner = $this->runner(new SocketConnection(true, peerCertificate: FakeSocketConnector::fixtureCertificate()), '2126-10-10 00:00:00');

        $result = $runner->run($this->monitor());

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertSame('Certificate expired', $result->message);
    }

    public function testHandshakeFailureIsDown(): void
    {
        self::assertSame('certificate verify failed', $this->runner(new SocketConnection(false, 0, 'certificate verify failed'))->run($this->monitor())->message);
        self::assertSame('SSL handshake failed (errno 111)', $this->runner(new SocketConnection(false, 111))->run($this->monitor())->message);
    }

    public function testMissingOrUnreadableCertificateIsUnknown(): void
    {
        $missing = $this->runner(new SocketConnection(true))->run($this->monitor());
        self::assertSame(CheckStatus::Unknown, $missing->status);
        self::assertSame('Could not capture peer certificate', $missing->message);

        $garbage = $this->runner(new SocketConnection(true, peerCertificate: 'not a certificate'))->run($this->monitor());
        self::assertSame(CheckStatus::Unknown, $garbage->status);
        self::assertSame('Could not parse certificate', $garbage->message);
    }

    public function testPrivateAndUnresolvableHostsAreRejectedBeforeConnecting(): void
    {
        $runner = $this->runner(new SocketConnection(true), connector: $connector);

        $private = $runner->run($this->monitor(['host' => '[::ffff:127.0.0.1]']));
        self::assertSame(CheckStatus::Down, $private->status);
        self::assertStringContainsString('private or local', (string) $private->message);

        self::assertSame('Could not resolve host nowhere.invalid', $runner->run($this->monitor(['host' => 'nowhere.invalid']))->message);
        self::assertSame([], $connector->calls);
    }

    public function testBlockingDisabledConnectsToTheName(): void
    {
        $connector = new FakeSocketConnector(new SocketConnection(false, 0, 'refused'));
        $runner    = new SslCheckRunner(OfflineDns::guard(), false, new MockClock(), $connector);

        $runner->run($this->monitor(['host' => '127.0.0.1', 'port' => 1]));

        self::assertSame('ssl://127.0.0.1:1', $connector->calls[0]['remote']);
    }

    public function testTimeoutIsClampedToTheGlobalMaximum(): void
    {
        $connector = new FakeSocketConnector(new SocketConnection(false, 111, 'refused'));
        $runner    = new SslCheckRunner(OfflineDns::guard(), false, new MockClock(), $connector, new CheckLimits(15.0));

        $runner->run($this->monitor(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 86400]));
        $runner->run($this->monitor(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.1]));

        self::assertSame(15.0, $connector->calls[0]['timeout']);
        self::assertSame(1.0, $connector->calls[1]['timeout']);
    }
}
