<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Schedule;

use Nowo\UptimeMonitorBundle\Schedule\UptimeMonitorScheduleProvider;
use PHPUnit\Framework\TestCase;

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

        $provider->getSchedule();
        $this->addToAssertionCount(1);
    }

    public function testGetScheduleWhenDisabled(): void
    {
        $provider = new UptimeMonitorScheduleProvider(['enabled' => false]);
        $provider->getSchedule();
        $this->addToAssertionCount(1);
    }

    public function testGetScheduleWhenModeIsNotScheduler(): void
    {
        $provider = new UptimeMonitorScheduleProvider([
            'enabled' => true,
            'mode'    => 'cron',
        ]);
        $provider->getSchedule();
        $this->addToAssertionCount(1);
    }
}
