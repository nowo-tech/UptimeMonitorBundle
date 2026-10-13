<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\HttpCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @covers \Nowo\UptimeMonitorBundle\Check\HttpCheckRunner
 */
final class HttpCheckRunnerTest extends TestCase
{
    public function testSupportsHttpTypes(): void
    {
        $runner  = $this->createRunner();
        $monitor = $this->createMonitor(MonitorType::Https);

        self::assertTrue($runner->supports($monitor));
    }

    public function testRunReturnsUpOnExpectedStatus(): void
    {
        $client  = new MockHttpClient([new MockResponse('ok', ['http_code' => 200])]);
        $runner  = $this->createRunner($client);
        $monitor = $this->createMonitor(MonitorType::Http);
        $monitor->setConfig(['url' => 'https://example.test/health']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame(200, $result->statusCode);
    }

    public function testRunReturnsDownOnUnexpectedStatus(): void
    {
        $client  = new MockHttpClient([new MockResponse('', ['http_code' => 503])]);
        $runner  = $this->createRunner($client);
        $monitor = $this->createMonitor(MonitorType::Http);
        $monitor->setConfig(['url' => 'https://example.test/']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertSame(503, $result->statusCode);
    }

    public function testRunReturnsDownWhenKeywordMissing(): void
    {
        $client  = new MockHttpClient([new MockResponse('nope', ['http_code' => 200])]);
        $runner  = $this->createRunner($client);
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
        $runner  = $this->createRunner($client);
        $monitor = $this->createMonitor(MonitorType::Https);
        $monitor->setConfig(['url' => 'https://example.test/']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertNull($result->statusCode);
    }

    public function testRunHonorsCustomHeadersAndSslOptions(): void
    {
        $client  = new MockHttpClient([new MockResponse('ok', ['http_code' => 200])]);
        $runner  = $this->createRunner($client);
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
        $runner  = $this->createRunner();
        $monitor = $this->createMonitor(MonitorType::Ping);

        self::assertFalse($runner->supports($monitor));
    }

    public function testRunReturnsDownWhenPrivateUrlBlocked(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTP client must not be called for blocked private URLs');
        });
        $runner  = $this->createRunner($client, true);
        $monitor = $this->createMonitor(MonitorType::Http);
        $monitor->setConfig(['url' => 'http://127.0.0.1/health']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertStringContainsString('private', (string) $result->message);
    }

    public function testRunAllowsPrivateUrlWhenBlockDisabled(): void
    {
        $client  = new MockHttpClient([new MockResponse('ok', ['http_code' => 200])]);
        $runner  = $this->createRunner($client, false);
        $monitor = $this->createMonitor(MonitorType::Http);
        $monitor->setConfig(['url' => 'http://127.0.0.1/health']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame(200, $result->statusCode);
    }

    public function testRunBlocksRedirectToPrivateNetworkWhenEnabled(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code'        => 302,
                'redirect_url'     => 'http://169.254.169.254/latest/meta-data/',
                'response_headers' => ['Location' => 'http://169.254.169.254/latest/meta-data/'],
            ]),
            new MockResponse('secret', ['http_code' => 200]),
        ]);
        $runner  = $this->createRunner($client, true);
        $monitor = $this->createMonitor(MonitorType::Http);
        $monitor->setConfig(['url' => 'http://8.8.8.8/', 'max_redirects' => 5]);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertStringContainsString('169.254.169.254', (string) $result->message);
    }

    public function testRunBlocksPrivateProxyWhenEnabled(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTP client must not be called with a private proxy');
        });
        $runner  = $this->createRunner($client, true);
        $monitor = $this->createMonitor(MonitorType::Http);
        $monitor->setConfig(['url' => 'http://8.8.8.8/', 'proxy' => 'http://10.0.0.1:3128']);

        $result = $runner->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertStringContainsString('proxy', (string) $result->message);
    }

    #[DataProvider('newlyBlockedUrlProvider')]
    public function testRunBlocksReservedRangesWhenEnabled(string $url): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTP client must not be called for blocked URLs');
        });
        $runner  = $this->createRunner($client, true);
        $monitor = $this->createMonitor(MonitorType::Http);
        $monitor->setConfig(['url' => $url]);

        self::assertSame(CheckStatus::Down, $runner->run($monitor)->status);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function newlyBlockedUrlProvider(): iterable
    {
        yield '0.0.0.0' => ['http://0.0.0.0/'];
        yield 'cgnat' => ['http://100.64.0.1/'];
        yield 'ula' => ['http://[fd00::1]/'];
        yield 'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'];
        yield 'unspecified ipv6' => ['http://[::]/'];
    }

    private function createRunner(?HttpClientInterface $client = null, bool $blockPrivateUrls = false): HttpCheckRunner
    {
        return new HttpCheckRunner(
            $client,
            new MonitorUrlSsrfGuard(),
            $blockPrivateUrls,
        );
    }

    private function createMonitor(MonitorType $type): Monitor
    {
        $tenant = new Tenant('main', 'Main');

        return new Monitor($tenant, 'Example', $type, 'https://example.test/');
    }
}
