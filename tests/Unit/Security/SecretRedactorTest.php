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
        yield 'password with a raw slash and port-like text' => ['https://admin:4242/rest@host.example.com/', 'https://***'];
        yield 'password with a raw slash' => ['https://admin:pa/ss@host.example.com/x?token=abc', 'https://***'];
        yield 'password with a raw question mark' => ['https://admin:pa?ss@host.example.com/', 'https://***'];
        yield 'password with a raw hash' => ['https://admin:pa#ss@host.example.com/', 'https://***'];
        yield 'user with a slash' => ['https://us/er:pw@host.example.com/', 'https://***'];
        yield 'raw at sign and slash in the password' => ['https://u:p@s/s@host.example.com/', 'https://***'];
        yield 'ipv6 with an at sign later' => ['http://[::1]:8080/x@y:z', 'http://***'];
        yield 'port and an e-mail in the query' => ['https://api.example.com:8443/notify?email=ops@example.com', 'https://***'];
        yield 'at sign in the path, no colon before it' => ['https://example.test/@me', 'https://example.test/@me'];
        yield 'e-mail in the query' => ['https://example.test/x?email=a@b.example', 'https://example.test/x?email=a@b.example'];
        yield 'connection string is not a url' => ['Server=db;User=sa;Password=Secret123;', 'Server=db;User=sa;Password=Secret123;'];
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
        yield 'password with a raw slash and port-like text' => ['https://admin:4242/rest@host.example.com/', 'https://***'];
        yield 'password with a raw slash' => ['https://admin:pa/ss@host.example.com/x?token=abc', 'https://***'];
        yield 'password with a raw question mark' => ['https://admin:pa?ss@host.example.com/', 'https://***'];
        yield 'password with a raw hash' => ['https://admin:pa#ss@host.example.com/', 'https://***'];
        yield 'user with a slash' => ['https://us/er:pw@host.example.com/', 'https://***'];
        yield 'raw at sign and slash in the password' => ['https://u:p@s/s@host.example.com/', 'https://***'];
        yield 'ipv6 with an at sign later' => ['http://[::1]:8080/x@y:z', 'http://***'];
        yield 'port and an e-mail in the query' => ['https://api.example.com:8443/notify?email=ops@example.com', 'https://***'];
        yield 'proxy without a scheme and a raw slash' => ['u:pa/ss@proxy.test:3128', '***'];
        yield 'at sign in the path, no colon before it' => ['https://example.test/@me', 'https://example.test/@me'];
        yield 'e-mail in the query' => ['https://example.test/x?email=a@b.example', 'https://example.test/x?email=a@b.example'];
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
        yield 'ambiguous stored value, opaque mask unchanged' => ['https://***', 'https://admin:4242/rest@host.example.com/x?token=abc', 'https://admin:4242/rest@host.example.com/x?token=abc'];
        yield 'ambiguous stored value, anything else is retyped' => ['https://***@host.example.com/x', 'https://admin:pa/ss@host.example.com/x', 'https://***@host.example.com/x'];
        yield 'ambiguous stored value, retyped' => ['https://new:pw@host.example.com/x', 'https://admin:pa/ss@host.example.com/x', 'https://new:pw@host.example.com/x'];
        yield 'ambiguous stored ipv6 value' => ['http://***', 'http://[::1]:8080/x@y:z', 'http://[::1]:8080/x@y:z'];
        yield 'ambiguous submitted value is never split' => ['https://***@attacker.example/a:b@api.example.com', 'https://u:pw@api.example.com/', 'https://***@attacker.example/a:b@api.example.com'];
        yield '& inside the stored userinfo' => ['https://***@example.test/?token=***', 'https://a&token=x@example.test/?token=t1', 'https://a&token=x@example.test/?token=t1'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function awkwardUrlProvider(): iterable
    {
        yield 'password with a raw slash and port-like text' => ['https://admin:4242/rest@host.example.com/'];
        yield 'password with a raw slash' => ['https://admin:pa/ss@host.example.com/x?token=abc&page=2'];
        yield 'password with a raw question mark' => ['https://admin:pa?ss@host.example.com/'];
        yield 'password with a raw hash' => ['https://admin:pa#ss@host.example.com/'];
        yield 'user with a slash' => ['https://us/er:pw@host.example.com/'];
        yield 'proxy without a scheme' => ['u:pa/ss@proxy.test:3128'];
        yield 'raw at sign and slash in the password' => ['https://u:p@s/s@host.example.com/'];
        yield 'ipv6 with an at sign later' => ['http://[::1]:8080/x@y:z'];
        yield 'port and an e-mail in the query' => ['https://api.example.com:8443/notify?email=ops@example.com'];
        yield 'userinfo and an e-mail in the query' => ['https://u:p@host.example.com/x?e=a@b'];
    }

    /**
     * The edit form shows no part of the credentials or host of an ambiguous URL, and saving it unchanged restores the
     * stored value exactly; the opaque mask never counts as the same origin.
     */
    #[DataProvider('awkwardUrlProvider')]
    public function testTheEditFormRoundTripKeepsTheStoredUrlExactly(string $stored): void
    {
        $masked = SecretRedactor::maskEditableUrl($stored);

        self::assertMatchesRegularExpression('#^(?:[a-z]+://)?\*\*\*$#', $masked);
        self::assertTrue(SecretRedactor::isAmbiguousUrl($stored));
        self::assertSame($stored, SecretRedactor::restoreEditableUrl($masked, $stored));
        self::assertTrue(SecretRedactor::keepsDestination($masked, $stored));
        self::assertFalse(SecretRedactor::sameOrigin($masked, $stored));
        self::assertFalse(SecretRedactor::keepsDestination($masked . 'x', $stored));
        self::assertSame('', SecretRedactor::stripMaskedUrl($masked), 'a mask with nothing stored behind it is dropped whole');
    }

    /**
     * The payloads that read as the stored host but reach another one: never the same origin, nothing is restored.
     *
     * @return iterable<string, array{string}>
     */
    public static function spoofedUrlProvider(): iterable
    {
        yield 'colon in the path' => ['https://attacker.example/a:b@api.example.com'];
        yield 'port and slash' => ['https://attacker.example:8443/x@api.example.com'];
        yield 'query' => ['https://attacker.example/?u=a:b@api.example.com'];
        yield 'fragment' => ['https://attacker.example/#a:b@api.example.com'];
        yield 'masked userinfo' => ['https://***@attacker.example/a:b@api.example.com'];
        yield 'bare host' => ['attacker.example/a:b@api.example.com'];
    }

    #[DataProvider('spoofedUrlProvider')]
    public function testASpoofedUrlIsNeverTheSameOriginAndRestoresNothing(string $submitted): void
    {
        $stored = 'https://u:pw@api.example.com/health';

        self::assertTrue(SecretRedactor::isAmbiguousUrl($submitted));
        self::assertFalse(SecretRedactor::sameOrigin($submitted, $stored));
        self::assertFalse(SecretRedactor::sameOrigin($stored, $submitted));
        self::assertFalse(SecretRedactor::keepsDestination($submitted, $stored));
        self::assertSame($submitted, SecretRedactor::restoreEditableUrl($submitted, $stored));
        self::assertSame($submitted, SecretRedactor::stripMaskedUrl($submitted));
    }

    public function testAnOriginIsTheDestinationOfTheRawValue(): void
    {
        self::assertTrue(SecretRedactor::sameOrigin('https://***@api.example.com/x', 'https://u:pw@api.example.com:443/y'));
        self::assertFalse(SecretRedactor::sameOrigin('https://***@attacker.example/x', 'https://u:pw@api.example.com/x'));
        self::assertTrue(SecretRedactor::sameOrigin('https://u:p@ss@api.example.com/x', 'https://***@api.example.com/'), 'a raw @ in the password');
        self::assertTrue(SecretRedactor::sameOrigin('https://api.example.com/x?email=a@b.example', 'https://api.example.com/'), 'an e-mail in the query is no userinfo');
    }

    public function testAMaskWithNothingStoredBehindItIsStrippedForAwkwardUrlsToo(): void
    {
        self::assertSame('https://host.example.com/x', SecretRedactor::stripMaskedUrl('https://***@host.example.com/x'));
        self::assertSame('', SecretRedactor::stripMaskedUrl('https://***'));
        self::assertSame('', SecretRedactor::stripMaskedUrl('***'));
        self::assertSame('https://admin:pa/ss@host.example.com/', SecretRedactor::stripMaskedUrl('https://admin:pa/ss@host.example.com/'), 'a typed value is kept');
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
