<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Ui;

use Nowo\UptimeMonitorBundle\Ui\SettingsSections;
use Nowo\UptimeMonitorBundle\Ui\UptimeAreaNav;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsSections::class)]
final class SettingsSectionsTest extends TestCase
{
    public function testEveryScreenByDefault(): void
    {
        $sections = new SettingsSections();

        self::assertSame(UptimeAreaNav::SETTINGS_SECTIONS, $sections->all());
        self::assertSame('general', $sections->first());
        self::assertTrue($sections->has('about'));
    }

    public function testTheKeptScreensInTheBundleOrder(): void
    {
        $sections = new SettingsSections(['settings' => ['sections' => ['history', 'tags', 'unknown']]]);

        self::assertSame(['tags' => 'nowo_uptime_settings_tags', 'history' => 'nowo_uptime_settings_history'], $sections->all());
        self::assertSame('tags', $sections->first());
        self::assertFalse($sections->has('general'));
    }

    public function testNoScreen(): void
    {
        self::assertNull((new SettingsSections(['settings' => ['sections' => []]]))->first());
    }
}
