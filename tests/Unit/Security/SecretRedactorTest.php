<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Security;

use Nowo\UptimeMonitorBundle\Security\SecretRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretRedactor::class)]
final class SecretRedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urlProvider(): iterable
    {
        yield 'user and password' => ['https://user:secret@example.test/health?x=1', 'https://***@example.test/health?x=1'];
        yield 'user only' => ['http://token@example.test', 'http://***@example.test'];
        yield 'no userinfo' => ['https://example.test/a@b', 'https://example.test/a@b'];
        yield 'bare host' => ['db.example:5432', 'db.example:5432'];
        yield 'mail-like host' => ['user@example.test', 'user@example.test'];
    }

    #[DataProvider('urlProvider')]
    public function testMaskUrl(string $value, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::maskUrl($value));
    }

    public function testRedactMonitorConfig(): void
    {
        $config = SecretRedactor::redactMonitorConfig([
            'url'           => 'https://user:pw@example.test/',
            'proxy'         => 'http://p:q@proxy.example:3128',
            'auth_username' => 'admin',
            'auth_password' => 'hunter2',
            'headers'       => ['Authorization' => 'Bearer abc', 'X-Trace' => '1'],
            'method'        => 'GET',
        ]);

        self::assertSame([
            'url'           => 'https://***@example.test/',
            'proxy'         => 'http://***@proxy.example:3128',
            'auth_username' => 'admin',
            'auth_password' => '',
            'headers'       => ['Authorization' => '***', 'X-Trace' => '***'],
            'method'        => 'GET',
        ], $config);
        self::assertSame(['method' => 'GET'], SecretRedactor::redactMonitorConfig(['method' => 'GET']));
    }
}
