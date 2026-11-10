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

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function originProvider(): iterable
    {
        yield 'same origin, other path and userinfo' => ['https://***@Api.Test/a?x=1', 'https://u:p@api.test/b', true];
        yield 'default port spelled out' => ['https://api.test:443/', 'https://api.test/', true];
        yield 'http default port' => ['http://api.test', 'http://api.test:80', true];
        yield 'host changed' => ['https://***@evil.test/', 'https://u:p@api.test/', false];
        yield 'port changed' => ['https://api.test:8443/', 'https://api.test/', false];
        yield 'scheme changed' => ['http://api.test/', 'https://api.test/', false];
        yield 'proxy without scheme, same' => ['***@proxy.test:3128', 'p:q@proxy.test:3128', true];
        yield 'proxy host changed' => ['***@other.test:3128', 'p:q@proxy.test:3128', false];
        yield 'other scheme, no default port' => ['socks5://proxy.test', 'socks5://proxy.test:1080', false];
        yield 'both empty' => ['', '', true];
        yield 'unparsable, identical' => ['http://', 'http://', true];
        yield 'unparsable, different' => ['http://', 'https://', false];
    }

    #[DataProvider('originProvider')]
    public function testSameOrigin(string $a, string $b, bool $expected): void
    {
        self::assertSame($expected, SecretRedactor::sameOrigin($a, $b));
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function stripProvider(): iterable
    {
        yield 'no mask' => ['https://u:p@example.test/?token=t', 'https://u:p@example.test/?token=t'];
        yield 'masked userinfo' => ['https://***@example.test/h', 'https://example.test/h'];
        yield 'masked userinfo, no scheme' => ['***@proxy.test:3128', 'proxy.test:3128'];
        yield 'typed userinfo with *** inside kept' => ['https://a***@example.test/', 'https://a***@example.test/'];
        yield 'only parameter' => ['https://example.test/?token=***', 'https://example.test/'];
        yield 'first parameter' => ['https://example.test/?token=***&page=2', 'https://example.test/?page=2'];
        yield 'last parameter' => ['https://example.test/?page=2&tokenx=***', 'https://example.test/?page=2'];
        yield 'several, with a fragment' => ['https://***@example.test/?a=***&b=***&c=1&d=***#f', 'https://example.test/?c=1#f'];
        yield 'all parameters, with a fragment' => ['https://example.test/?a=***&b=***#f', 'https://example.test/#f'];
        yield 'partial mask kept' => ['https://example.test/?token=a***', 'https://example.test/?token=a***'];
    }

    /** Since 1.25.2 (KE-U151-L1): a mask left with nothing behind it is dropped, never sent as `***`. */
    #[DataProvider('stripProvider')]
    public function testStripMaskedUrl(string $value, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::stripMaskedUrl($value));
    }

    public function testStripMaskedHeaders(): void
    {
        self::assertSame(
            ['Authorization' => 'Bearer x', 'X-Empty' => '', 'X-Part' => 'a***'],
            SecretRedactor::stripMaskedHeaders(['Authorization' => 'Bearer x', 'X-Other' => '***', 'X-Empty' => '', 'X-Part' => 'a***']),
        );
    }

    /** Since 1.25.2 (KE-U151-I1): `jwt`, `bearer`, `hash` and `signature` values are masked too; path secrets are not. */
    public function testMoreCredentialLikeNamesAreMasked(): void
    {
        self::assertSame(
            'https://example.test/?jwt=***&bearer=***&hash=***&signature=***&q=x',
            SecretRedactor::maskUrl('https://example.test/?jwt=a&bearer=b&hash=c&signature=d&q=x'),
        );
        self::assertSame(
            'https://example.test/?access_jwt=***&Hash=***',
            SecretRedactor::maskEditableUrl('https://example.test/?access_jwt=a&Hash=b'),
        );
        self::assertSame(
            'https://example.test/?jwt=a&page=2',
            SecretRedactor::restoreEditableUrl('https://example.test/?jwt=***&page=2', 'https://example.test/?jwt=a&page=1'),
        );
        $path = 'https://hooks.slack.test/services/T000/B000/XXXX';
        self::assertSame($path, SecretRedactor::maskUrl($path), 'documented: a secret in the path is not masked');
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
