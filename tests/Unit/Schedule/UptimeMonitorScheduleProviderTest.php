<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Schedule;

use Nowo\UptimeMonitorBundle\Schedule\UptimeMonitorScheduleProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Schedule;

/**
 * @covers \Nowo\UptimeMonitorBundle\Schedule\UptimeMonitorScheduleProvider
 */
final class UptimeMonitorScheduleProviderTest extends TestCase
{
    public function testGetScheduleDoesNotThrow(): void
    {
        $provider = new UptimeMonitorScheduleProvider([
            'enabled' => true,
            'mode'    => 'scheduler',
            'tick'    => '1 minute',
        ]);

        self::assertInstanceOf(Schedule::class, $provider->getSchedule());
    }

    public function testGetScheduleWhenDisabled(): void
    {
        $provider = new UptimeMonitorScheduleProvider(['enabled' => false]);
        self::assertInstanceOf(Schedule::class, $provider->getSchedule());
    }

    public function testGetScheduleWhenModeIsNotScheduler(): void
    {
        $provider = new UptimeMonitorScheduleProvider([
            'enabled' => true,
            'mode'    => 'cron',
        ]);
        self::assertInstanceOf(Schedule::class, $provider->getSchedule());
    }
}
