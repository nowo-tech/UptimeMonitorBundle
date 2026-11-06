<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Ui;

use Nowo\UptimeMonitorBundle\Ui\SettingsSections;
use Nowo\UptimeMonitorBundle\Ui\SettingsSectionsResolverInterface;
use Nowo\UptimeMonitorBundle\Ui\UptimeAreaNav;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function str_starts_with;

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

    public function testTheResolverNarrowsTheScreensPerTenant(): void
    {
        $resolver = $this->createStub(SettingsSectionsResolverInterface::class);
        $resolver->method('sections')->willReturnCallback(static fn (string $slug): array => str_starts_with($slug, 'user-') ? [] : ['about', 'history', 'general']);
        $sections = new SettingsSections(['settings' => ['sections' => ['history', 'tags', 'about']]], $resolver);

        self::assertSame(['history' => 'nowo_uptime_settings_history', 'about' => 'nowo_uptime_settings_about'], $sections->all('acme'), 'the configured list stays the ceiling, in the bundle order');
        self::assertSame('history', $sections->first('acme'));
        self::assertFalse($sections->has('general', 'acme'));
        self::assertSame([], $sections->all('user-1'));
        self::assertNull($sections->first('user-1'));
        self::assertTrue($sections->has('tags'), 'without a tenant the resolver is not asked');
    }
}
