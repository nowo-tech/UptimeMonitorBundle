<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Monitor;

use Nowo\UptimeMonitorBundle\Monitor\StatusCodeMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusCodeMatcher::class)]
final class StatusCodeMatcherTest extends TestCase
{
    public function testParsesRangesAndLists(): void
    {
        $codes = StatusCodeMatcher::parse('200-299, 301');

        self::assertContains(200, $codes);
        self::assertContains(299, $codes);
        self::assertContains(301, $codes);
        self::assertNotContains(300, $codes);
    }

    public function testMatches(): void
    {
        $allowed = StatusCodeMatcher::parse('200-299');

        self::assertTrue(StatusCodeMatcher::matches(204, $allowed));
        self::assertFalse(StatusCodeMatcher::matches(404, $allowed));
    }

    public function testSkipsEmptyPartsSwapsReversedRangesAndFallsBackTo200(): void
    {
        self::assertSame([204, 205, 206], StatusCodeMatcher::parse('206-204, ,'));
        self::assertSame([200], StatusCodeMatcher::parse(' , 42, 700'));
        self::assertTrue(StatusCodeMatcher::matches(205, [204, 205]));
        self::assertFalse(StatusCodeMatcher::matches(500, [204, 205]));
    }

    public function testFormatsRunsOfThreeOrMoreAsRanges(): void
    {
        self::assertSame('200-299', StatusCodeMatcher::format(StatusCodeMatcher::parse('200-299')));
        self::assertSame('200-299,301,404', StatusCodeMatcher::format([404, ...StatusCodeMatcher::parse('200-299'), 301, 301]));
        self::assertSame('200,201,204', StatusCodeMatcher::format([204, 201, 200]));
        self::assertSame('200-202', StatusCodeMatcher::format(['200', 201, '202', 'x', 2.5, null]));
        self::assertSame('', StatusCodeMatcher::format([]));
    }
}
