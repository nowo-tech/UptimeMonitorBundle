<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\HttpCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @covers \Nowo\UptimeMonitorBundle\Check\HttpCheckRunner
 */
final class HttpCheckRunnerTest extends TestCase
{
    public function testSupportsHttpTypes(): void
    {
        $runner  = new HttpCheckRunner();
        $monitor = $this->createMonitor(MonitorType::Https);

        self::assertTrue($runner->supports($monitor));
    }

    public function testRunReturnsUpOnExpectedStatus(): void
    {
        $client  = new MockHttpClient([new MockResponse('ok', ['http_code' => 200])]);
        $runner  = new HttpCheckRunner($client);
        $monitor = $this->createMonitor(MonitorType::Http);
        $monitor->setConfig(['url' => 'https://example.test/health']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame(200, $result->statusCode);
    }

    public function testRunReturnsDownOnUnexpectedStatus(): void
    {
        $client  = new MockHttpClient([new MockResponse('', ['http_code' => 503])]);
        $runner  = new HttpCheckRunner($client);
        $monitor = $this->createMonitor(MonitorType::Http);
        $monitor->setConfig(['url' => 'https://example.test/']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertSame(503, $result->statusCode);
    }

    public function testRunReturnsDownWhenKeywordMissing(): void
    {
        $client  = new MockHttpClient([new MockResponse('nope', ['http_code' => 200])]);
        $runner  = new HttpCheckRunner($client);
        $monitor = $this->createMonitor(MonitorType::Http);
        $monitor->setConfig(['url' => 'https://example.test/', 'keyword' => 'ok']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
    }

    public function testRunReturnsDownOnTransportError(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('connection failed');
        });
        $runner  = new HttpCheckRunner($client);
        $monitor = $this->createMonitor(MonitorType::Https);
        $monitor->setConfig(['url' => 'https://example.test/']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertNull($result->statusCode);
    }

    public function testRunHonorsCustomHeadersAndSslOptions(): void
    {
        $client  = new MockHttpClient([new MockResponse('ok', ['http_code' => 200])]);
        $runner  = new HttpCheckRunner($client);
        $monitor = $this->createMonitor(MonitorType::Https);
        $monitor->setConfig([
            'url'                   => 'https://example.test/',
            'headers'               => ['X-Test' => '1'],
            'verify_ssl'            => false,
            'expected_status_codes' => 'not-array',
        ]);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Up, $result->status);
    }

    public function testSupportsReturnsFalseForPing(): void
    {
        $runner  = new HttpCheckRunner();
        $monitor = $this->createMonitor(MonitorType::Ping);

        self::assertFalse($runner->supports($monitor));
    }

    private function createMonitor(MonitorType $type): Monitor
    {
        $tenant = new Tenant('main', 'Main');

        return new Monitor($tenant, 'Example', $type, 'https://example.test/');
    }
}
