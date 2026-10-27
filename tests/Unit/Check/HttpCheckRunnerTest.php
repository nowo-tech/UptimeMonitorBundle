<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Check;

use Nowo\UptimeMonitorBundle\Check\CheckLimits;
use Nowo\UptimeMonitorBundle\Check\HttpCheckRunner;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Tests\Support\FakeSocketConnector;
use Nowo\UptimeMonitorBundle\Tests\Support\OfflineDns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(HttpCheckRunner::class)]
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

    public function testRequestOptionsCarryBodyHeadersProxyAuthAndTls(): void
    {
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('all good', ['http_code' => 201]);
        });
        $monitor = $this->createMonitor(MonitorType::Https);
        $monitor->setConfig([
            'url'                   => 'https://example.test/api',
            'method'                => 'post',
            'body'                  => '<a/>',
            'body_encoding'         => 'xml',
            'ignore_tls'            => true,
            'proxy'                 => 'http://proxy.example:3128',
            'auth_method'           => 'basic',
            'auth_username'         => 'user',
            'auth_password'         => 'secret',
            'keyword'               => 'good',
            'expected_status_codes' => '201',
            'timeout'               => 5,
        ]);

        $result = $this->createRunner($client)->run($monitor);

        self::assertSame(CheckStatus::Up, $result->status);
        self::assertSame('POST', $seen[0]['method']);
        self::assertSame('<a/>', $seen[0]['options']['body']);
        self::assertContains('Content-Type: application/xml', $seen[0]['options']['headers']);
        self::assertSame('http://proxy.example:3128', $seen[0]['options']['proxy']);
        self::assertContains('Authorization: Basic ' . base64_encode('user:secret'), $seen[0]['options']['headers']);
        self::assertFalse($seen[0]['options']['verify_peer']);
        self::assertFalse($seen[0]['options']['verify_host']);
        self::assertSame(5.0, $seen[0]['options']['timeout']);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function bodyEncodingProvider(): iterable
    {
        yield 'json' => ['json', [], 'Content-Type: application/json'];
        yield 'text' => ['form', [], 'Content-Type: text/plain'];
        yield 'explicit content type wins' => ['json', ['content-type' => 'application/x-custom'], 'content-type: application/x-custom'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('bodyEncodingProvider')]
    public function testBodyEncodingSetsContentType(string $encoding, array $headers, string $expectedHeader): void
    {
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = (array) $options['headers'];

            return new MockResponse('ok');
        });
        $monitor = $this->createMonitor(MonitorType::Http);
        $monitor->setConfig(['body' => 'payload', 'body_encoding' => $encoding, 'headers' => $headers]);

        $this->createRunner($client)->run($monitor);

        self::assertContains($expectedHeader, $seen);
        self::assertCount(1, array_filter($seen, static fn (string $h): bool => stripos($h, 'content-type:') === 0));
    }

    public function testUnexpectedStatusListsShortOrCountsLongExpectations(): void
    {
        $monitor = $this->createMonitor(MonitorType::Http);

        $monitor->setConfig(['expected_status_codes' => '200,204']);
        $short = $this->createRunner(new MockHttpClient([new MockResponse('', ['http_code' => 500])]))->run($monitor);
        self::assertSame('Unexpected status code 500 (expected 200,204)', $short->message);

        $monitor->setConfig(['expected_status_codes' => '200-299']);
        $long = $this->createRunner(new MockHttpClient([new MockResponse('', ['http_code' => 500])]))->run($monitor);
        self::assertSame('Unexpected status code 500 (expected 100 codes)', $long->message);
    }

    public function testCertificateExpiryReadsTheChainOfTheCheckedConnection(): void
    {
        $chain   = ['peer_certificate_chain' => [FakeSocketConnector::fixtureCertificate()]];
        $client  = static fn (array $info): MockHttpClient => new MockHttpClient([new MockResponse('ok', $info)]);
        $monitor = $this->createMonitor(MonitorType::Https);
        $monitor->setConfig(['url' => 'https://secure.example:8443/', 'check_cert_expiry' => true]);

        $near = $this->createRunner($client($chain), false, '2126-09-25 00:00:00')->run($monitor);
        self::assertSame(CheckStatus::Degraded, $near->status);
        self::assertSame('TLS certificate expires in 7 day(s)', $near->message);

        $expired = $this->createRunner($client($chain), false, '2127-01-01 00:00:00')->run($monitor);
        self::assertSame('TLS certificate expires in 0 day(s)', $expired->message);

        $far = $this->createRunner($client($chain), false, '2126-01-01 00:00:00')->run($monitor);
        self::assertSame(CheckStatus::Up, $far->status);
    }

    public function testCertificateExpiryIgnoresMissingChainAndPlainHttp(): void
    {
        /** @var list<array<string, mixed>> $seen */
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse('ok');
        });
        $monitor = $this->createMonitor(MonitorType::Https);
        $monitor->setConfig(['url' => 'https://secure.example/', 'check_cert_expiry' => true]);
        self::assertSame(CheckStatus::Up, $this->createRunner($client)->run($monitor)->status);

        $http = $this->createMonitor(MonitorType::Http);
        $http->setConfig(['url' => 'http://secure.example/', 'check_cert_expiry' => true]);
        self::assertSame(CheckStatus::Up, $this->createRunner($client)->run($http)->status);
        self::assertCount(2, $seen);
    }

    public function testTimeoutAndRedirectsAreClampedToTheGlobalLimits(): void
    {
        /** @var list<array<string, mixed>> $seen */
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse('ok');
        });
        $monitor = $this->createMonitor(MonitorType::Https);
        $monitor->setConfig(['url' => 'https://example.test/', 'timeout' => 86400, 'max_redirects' => 500]);

        $this->createRunner($client, false, 'now', new CheckLimits(30.0, 1024, 5))->run($monitor);

        self::assertSame(30.0, $seen[0]['timeout']);
        self::assertSame(32.0, $seen[0]['max_duration']);
        self::assertSame(5, $seen[0]['max_redirects']);
    }

    public function testBodyIsNotReadWithoutKeyword(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->expects(self::never())->method('getContent');
        $response->expects(self::once())->method('cancel');

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $client->expects(self::never())->method('stream');

        $monitor = $this->createMonitor(MonitorType::Https);
        $monitor->setConfig(['url' => 'https://example.test/']);

        self::assertSame(CheckStatus::Up, $this->createRunner($client)->run($monitor)->status);
    }

    public function testKeywordIsSearchedInTheFirstMaxBodyBytesOnly(): void
    {
        $monitor = $this->createMonitor(MonitorType::Https);
        $monitor->setConfig(['url' => 'https://example.test/', 'keyword' => 'needle']);
        $limits = new CheckLimits(60.0, 16, 20);

        $early = new MockResponse(['needle', str_repeat('x', 64), 'tail']);
        self::assertSame(CheckStatus::Up, $this->createRunner(new MockHttpClient([$early]), false, 'now', $limits)->run($monitor)->status);

        $late   = new MockResponse([str_repeat('x', 32), 'needle']);
        $result = $this->createRunner(new MockHttpClient([$late]), false, 'now', $limits)->run($monitor);
        self::assertSame(CheckStatus::Down, $result->status);
        self::assertSame('Response body does not contain expected keyword', $result->message);

        $short = new MockResponse(['abc', 'needle']);
        self::assertSame(CheckStatus::Up, $this->createRunner(new MockHttpClient([$short]), false, 'now', $limits)->run($monitor)->status);
    }

    public function testProxyHostIsResolvedOnceAndPinnedToThePublicIp(): void
    {
        /** @var list<array<string, mixed>> $seen */
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse('ok');
        });
        $monitor = $this->createMonitor(MonitorType::Https);

        $monitor->setConfig(['url' => 'http://8.8.8.8/', 'proxy' => 'http://user:secret@proxy.example:3128']);
        self::assertSame(CheckStatus::Up, $this->createRunner($client, true)->run($monitor)->status);
        self::assertSame('http://user:secret@93.184.215.20:3128', $seen[0]['proxy']);

        $monitor->setConfig(['url' => 'http://8.8.8.8/', 'proxy' => 'http://user@proxy.example']);
        $this->createRunner($client, true)->run($monitor);
        self::assertSame('http://user@93.184.215.20', $seen[1]['proxy']);

        $monitor->setConfig(['url' => 'http://8.8.8.8/', 'proxy' => 'http://8.8.8.8:3128']);
        $this->createRunner($client, true)->run($monitor);
        self::assertSame('http://8.8.8.8:3128', $seen[2]['proxy']);

        $monitor->setConfig(['url' => 'http://8.8.8.8/', 'proxy' => 'http://proxy6.example:3128']);
        $this->createRunner($client, true)->run($monitor);
        self::assertSame('http://[2606:2800:21f:cb07:6820:80da:af6b:8b2c]:3128', $seen[3]['proxy']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedProxyProvider(): iterable
    {
        yield 'resolves to a private address' => ['http://rebind.example:3128'];
        yield 'does not resolve' => ['http://unknown.example:3128'];
        yield 'no host' => ['http:///nohost'];
    }

    #[DataProvider('rejectedProxyProvider')]
    public function testUnsafeOrUnresolvableProxyIsRejected(string $proxy): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTP client must not be called with a rejected proxy');
        });
        $monitor = $this->createMonitor(MonitorType::Https);
        $monitor->setConfig(['url' => 'http://8.8.8.8/', 'proxy' => $proxy]);

        $result = $this->createRunner($client, true)->run($monitor);

        self::assertSame(CheckStatus::Down, $result->status);
        self::assertStringContainsString('proxy', (string) $result->message);
    }

    private function createRunner(
        ?HttpClientInterface $client = null,
        bool $blockPrivateUrls = false,
        string $now = 'now',
        CheckLimits $limits = new CheckLimits(),
    ): HttpCheckRunner {
        return new HttpCheckRunner(
            $client,
            OfflineDns::guard(
                ['example.test' => ['93.184.215.14'], 'proxy.example' => ['93.184.215.20'], 'rebind.example' => ['10.0.0.5']],
                ['proxy6.example' => ['2606:2800:21f:cb07:6820:80da:af6b:8b2c']],
            ),
            $blockPrivateUrls,
            new MockClock($now === 'now' ? 'now' : $now . ' UTC'),
            $limits,
        );
    }

    private function createMonitor(MonitorType $type): Monitor
    {
        $tenant = new Tenant('main', 'Main');

        return new Monitor($tenant, 'Example', $type, 'https://example.test/');
    }
}
