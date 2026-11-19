<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Search;

use Nowo\UptimeMonitorBundle\Search\MonitorSearchUrl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonitorSearchUrl::class)]
final class MonitorSearchUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function targets(): iterable
    {
        yield 'plain' => ['https://Example.com/health', 'https://example.com'];
        yield 'userinfo and query' => ['https://user:p@ss@example.com:8443/a/b?token=abc#frag', 'https://example.com:8443'];
        yield 'secret in path' => ['https://api.test/bot123:SECRET/ping', 'https://api.test'];
        yield 'bare host' => ['db.internal:5432', 'db.internal:5432'];
        yield 'bare host with userinfo' => ['user:pw@db.internal:5432', 'db.internal:5432'];
        yield 'bare host no port' => ['example.org', 'example.org'];
        yield 'password with a raw slash' => ['https://admin:4242/rest@host.example.com/', ''];
        yield 'password with a raw slash and no port-like text' => ['https://admin:pa/ss@host.example.com/x', ''];
        yield 'password with a raw question mark' => ['https://admin:pa?ss@host.example.com/', ''];
        yield 'password with a raw hash' => ['https://admin:pa#ss@host.example.com/', ''];
        yield 'user with a slash' => ['https://us/er:pw@host.example.com/', ''];
        yield 'at sign in the path' => ['https://host.example.com/@me', 'https://host.example.com'];
        yield 'at sign and colon in the path' => ['https://host.example.com/a:b@me', ''];
        yield 'e-mail in the query' => ['https://host.example.com/x?email=a@b.example', 'https://host.example.com'];
        yield 'e-mail in the query after a port' => ['https://host.example.com:8443?email=a@b.example', ''];
        yield 'connection string' => ['Server=db;User=sa;Password=Secret123;', ''];
        yield 'connection string with a url scheme' => ['postgres://user:pw@db:5432/app', 'postgres://db:5432'];
        yield 'not a host' => ['https://ex ample.com/', ''];
        yield 'port out of range' => ['https://host.example.com:99999/', ''];
        yield 'ipv6' => ['http://[::1]:8080/x', 'http://[::1]:8080'];
        yield 'ipv4' => ['10.0.0.1:22', '10.0.0.1:22'];
        yield 'unreadable' => ['', ''];
        yield 'only userinfo' => ['https://user:pw@', ''];
    }

    #[DataProvider('targets')]
    public function testDisplay(string $target, string $expected): void
    {
        self::assertSame($expected, MonitorSearchUrl::display($target));
    }
}
