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
        self::assertSame(['proxy' => '***@proxy.example:3128'], SecretRedactor::redactMonitorConfig(['proxy' => 'p:q@proxy.example:3128']), 'a proxy without a scheme (1.25.1)');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function editableUrlProvider(): iterable
    {
        yield 'user and password' => ['https://user:pw@example.test/h?x=1', 'https://***@example.test/h?x=1'];
        yield 'raw @ inside the password' => ['https://u:p@ss@example.test/x', 'https://***@example.test/x'];
        yield 'no scheme' => ['u:p@proxy.test:3128', '***@proxy.test:3128'];
        yield 'empty userinfo' => ['https://@example.test/', 'https://@example.test/'];
        yield 'no userinfo, @ in the path' => ['https://example.test/a@b', 'https://example.test/a@b'];
        yield 'query tokens, empty value kept' => ['https://example.test/?api_key=k&page=2&token=&sig=s#f', 'https://example.test/?api_key=***&page=2&token=&sig=***#f'];
        yield 'nothing secret' => ['https://example.test/health', 'https://example.test/health'];
    }

    #[DataProvider('editableUrlProvider')]
    public function testMaskEditableUrl(string $value, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::maskEditableUrl($value));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function restoreProvider(): iterable
    {
        $stored = 'https://u:pw@example.test/h?token=t1&token=t2&page=1';
        yield 'unchanged mask' => ['https://***@example.test/h?token=***&token=***&page=1', $stored, $stored];
        yield 'path and plain parameter edited' => ['https://***@example.test/v2?token=***&page=9', $stored, 'https://u:pw@example.test/v2?token=t1&page=9'];
        yield 'typed userinfo and token' => ['https://x:y@example.test/h?token=new', $stored, 'https://x:y@example.test/h?token=new'];
        yield 'userinfo removed' => ['https://example.test/h?token=***', $stored, 'https://example.test/h?token=t1'];
        yield 'more masks than stored values' => ['https://***@example.test/?token=***&token=***&token=***', $stored, 'https://u:pw@example.test/?token=t1&token=t2&token=***'];
        yield 'mask on a non-secret parameter' => ['https://***@example.test/?page=***', $stored, 'https://u:pw@example.test/?page=***'];
        yield 'nothing stored' => ['https://***@example.test/?token=***', '', 'https://***@example.test/?token=***'];
        yield 'no mask submitted' => ['https://example.test/', $stored, 'https://example.test/'];
        yield 'stored without userinfo' => ['https://***@example.test/?token=***', 'https://example.test/?token=t1', 'https://***@example.test/?token=t1'];
        yield 'stored with an empty userinfo' => ['https://***@example.test/', 'https://@example.test/', 'https://***@example.test/'];
        yield 'proxy without a scheme' => ['***@proxy.test:3128', 'p:q@proxy.test:3128', 'p:q@proxy.test:3128'];
        yield '& inside the stored userinfo' => ['https://***@example.test/?token=***', 'https://a&token=x@example.test/?token=t1', 'https://a&token=x@example.test/?token=t1'];
    }

    #[DataProvider('restoreProvider')]
    public function testRestoreEditableUrl(string $submitted, string $stored, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::restoreEditableUrl($submitted, $stored));
    }

    public function testEditableHeadersAreMaskedAndRestoredByName(): void
    {
        self::assertSame(
            ['Authorization' => '***', 'X-Empty' => '', '7' => '***'],
            SecretRedactor::maskEditableHeaders(['Authorization' => 'Bearer x', 'X-Empty' => '', 7 => ['odd']]),
        );

        $stored = ['Authorization' => 'Bearer x', 'X-Api-Key' => 'k', 'X-Bad' => ['odd']];
        self::assertSame(
            ['authorization' => 'Bearer x', 'X-Api-Key' => 'typed', 'X-New' => '***', 'X-Bad' => '***'],
            SecretRedactor::restoreEditableHeaders(['authorization' => '***', 'X-Api-Key' => 'typed', 'X-New' => '***', 'X-Bad' => '***'], $stored),
        );
        self::assertSame(['A' => '***'], SecretRedactor::restoreEditableHeaders(['A' => '***'], null));
    }

    public function testUrlsInsideFreeTextAreMasked(): void
    {
        // Obvious placeholder credentials, joined into the URL at runtime.
        $userinfo = 'user:REDACTED-TEST';
        self::assertSame(
            'cURL error 6: Could not resolve host for "https://***@api.test/h?token=***&page=2" (see http://docs.test).',
            SecretRedactor::maskUrlsInText('cURL error 6: Could not resolve host for "https://' . $userinfo . '@api.test/h?token=placeholder&page=2" (see http://docs.test).'),
        );
        self::assertSame('no url here', SecretRedactor::maskUrlsInText('no url here'));
    }
}
