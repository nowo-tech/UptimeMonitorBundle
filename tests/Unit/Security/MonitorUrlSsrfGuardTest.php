<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Security;

use Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SEC / Phase 7 — SSRF regression for monitor URL/host guards.
 *
 * @covers \Nowo\UptimeMonitorBundle\Security\MonitorUrlSsrfGuard
 */
final class MonitorUrlSsrfGuardTest extends TestCase
{
    #[DataProvider('blockedUrlProvider')]
    public function testBlocksPrivateAndLocalUrls(string $url): void
    {
        $guard = new MonitorUrlSsrfGuard();

        self::assertTrue($guard->isBlocked($url), $url);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function blockedUrlProvider(): iterable
    {
        yield 'loopback ipv4' => ['http://127.0.0.1/'];
        yield 'localhost hostname' => ['http://localhost/health'];
        yield 'rfc1918 10' => ['http://10.0.0.5/'];
        yield 'rfc1918 172.16' => ['http://172.16.1.1/'];
        yield 'rfc1918 192.168' => ['http://192.168.1.10/'];
        yield 'link-local metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'gcp metadata hostname' => ['http://metadata.google.internal/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'ipv6 link-local' => ['http://[fe80::1]/'];
        yield 'empty host' => ['http:///path'];
        yield 'no host' => ['not-a-url'];
        yield 'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'];
        yield 'cgnat' => ['http://100.64.0.1/'];
    }

    #[DataProvider('blockedHostProvider')]
    public function testBlocksPrivateHosts(string $host): void
    {
        $guard = new MonitorUrlSsrfGuard();

        self::assertTrue($guard->isBlockedHost($host), $host);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function blockedHostProvider(): iterable
    {
        yield 'localhost' => ['localhost'];
        yield '127.0.0.1' => ['127.0.0.1'];
        yield '::1' => ['::1'];
        yield '10.x' => ['10.1.2.3'];
        yield '192.168.x' => ['192.168.100.1'];
        yield 'metadata' => ['metadata.google.internal'];
        yield 'metadata trailing dot' => ['metadata.google.internal.'];
        yield 'localhost subdomain' => ['app.localhost'];
        yield 'this network 0.0.0.0' => ['0.0.0.0'];
        yield 'this network 0.1.2.3' => ['0.1.2.3'];
        yield 'cgnat 100.64/10' => ['100.64.0.1'];
        yield 'ula fc00::/7' => ['fc00::1'];
        yield 'ula fd' => ['[fd12:3456::1]'];
        yield 'ipv4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'unspecified ipv6' => ['::'];
        yield 'link-local with zone id' => ['fe80::1%eth0'];
        yield 'decimal loopback' => ['2130706433'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'broadcast' => ['255.255.255.255'];
    }

    public function testIsBlockedIpRejectsNonIp(): void
    {
        self::assertTrue((new MonitorUrlSsrfGuard())->isBlockedIp('not-an-ip'));
        self::assertFalse((new MonitorUrlSsrfGuard())->isBlockedIp('1.1.1.1'));
    }

    public function testResolveAddresses(): void
    {
        $guard = new MonitorUrlSsrfGuard();

        self::assertSame(['8.8.8.8'], $guard->resolveAddresses('8.8.8.8'));
        self::assertSame(['::1'], $guard->resolveAddresses('[::1]'));
        self::assertSame([], $guard->resolveAddresses('does-not-exist.invalid'));
    }

    public function testIsBlockedResolutionChecksEveryAddress(): void
    {
        $guard = new MonitorUrlSsrfGuard();

        // A rebinding-style answer mixing public and private records must be blocked.
        self::assertTrue($guard->isBlockedResolution('rebind.example', ['8.8.8.8', '127.0.0.1']));
        self::assertFalse($guard->isBlockedResolution('public.example', ['8.8.8.8', '2001:4860:4860::8888']));
        self::assertFalse($guard->isBlockedHost('does-not-exist.invalid'));
    }

    public function testAllowsPublicHost(): void
    {
        $guard = new MonitorUrlSsrfGuard();

        self::assertFalse($guard->isBlocked('https://example.com/health'));
        self::assertFalse($guard->isBlockedHost('8.8.8.8'));
        self::assertFalse($guard->isBlockedHost('2001:4860:4860::8888'));
    }
}
