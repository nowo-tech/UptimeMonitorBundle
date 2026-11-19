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
        yield 'unreadable' => ['', ''];
        yield 'only userinfo' => ['https://user:pw@', ''];
    }

    #[DataProvider('targets')]
    public function testDisplay(string $target, string $expected): void
    {
        self::assertSame($expected, MonitorSearchUrl::display($target));
    }
}
