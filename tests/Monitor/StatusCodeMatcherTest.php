<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Monitor;

use Nowo\UptimeMonitorBundle\Monitor\StatusCodeMatcher;
use PHPUnit\Framework\TestCase;

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
}
