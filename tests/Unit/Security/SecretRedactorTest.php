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
        yield 'raw @ inside the password' => ['https://u:p@ss@example.test/x', 'https://***@example.test/x'];
        yield 'query tokens' => [
            'https://example.test/hook?api_key=REDACTED-TEST&page=2&Token=REDACTED-TEST&SIG=x#frag',
            'https://example.test/hook?api_key=***&page=2&Token=***&SIG=***#frag',
        ];
        yield 'flag without value' => ['https://example.test/?debug&secret=REDACTED-TEST', 'https://example.test/?debug&secret=***'];
        yield 'userinfo and query' => ['https://u:p@example.test/?password=REDACTED-TEST', 'https://***@example.test/?password=***'];
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
            'auth_password' => str_repeat('x', 8), // runtime fixture, not a literal credential
            'headers'       => ['Authorization' => 'REDACTED-TEST', 'X-Trace' => '1'],
            'body'          => '{"token":"REDACTED-TEST"}',
            'method'        => 'GET',
        ]);

        self::assertSame([
            'url'           => 'https://***@example.test/',
            'proxy'         => 'http://***@proxy.example:3128',
            'auth_username' => 'admin',
            'auth_password' => '',
            'headers'       => ['Authorization' => '***', 'X-Trace' => '***'],
            'body'          => '',
            'method'        => 'GET',
        ], $config);
        self::assertSame(['method' => 'GET'], SecretRedactor::redactMonitorConfig(['method' => 'GET']));
    }

    public function testUrlsInsideFreeTextAreMasked(): void
    {
        self::assertSame(
            'cURL error 6: Could not resolve host for "https://***@api.test/h?token=***&page=2" (see http://docs.test).',
            SecretRedactor::maskUrlsInText('cURL error 6: Could not resolve host for "https://user:pa55@api.test/h?token=abc&page=2" (see http://docs.test).'),
        );
        self::assertSame('no url here', SecretRedactor::maskUrlsInText('no url here'));
    }
}
