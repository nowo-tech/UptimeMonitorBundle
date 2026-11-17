<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Ui;

use Nowo\UptimeMonitorBundle\DependencyInjection\Configuration;
use Nowo\UptimeMonitorBundle\Ui\MonitorShowLayout;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(MonitorShowLayout::class)]
final class MonitorShowLayoutTest extends TestCase
{
    public function testTheDefaultsAreThe129Page(): void
    {
        $defaults = (new Processor())->processConfiguration(new Configuration(), [[]])['web_ui']['monitor_show'];
        $layout   = MonitorShowLayout::resolve($defaults);

        self::assertSame('bundle', $layout['charts']);
        self::assertSame([], $layout['order']);
        self::assertSame(['sortable' => false, 'align_numbers' => false, 'time_format' => 'Y-m-d H:i:s', 'time_title_format' => null, 'per_page' => null], $layout['log']);
        self::assertSame(['actions_layout' => 'toolbar', 'status' => false, 'icons' => []], $layout['header']);
        $empty = MonitorShowLayout::resolve([]);
        self::assertSame([$layout['charts'], $layout['order'], $layout['log'], $layout['header']], [$empty['charts'], $empty['order'], $empty['log'], $empty['header']], 'an empty array resolves to the same defaults');
    }

    public function testConfiguredValuesAndTheFullOrder(): void
    {
        $layout = MonitorShowLayout::resolve([
            'charts' => 'host',
            'slots'  => ['log' => 'host/_log.html.twig'],
            'order'  => ['figures', 'history', 'details', 'figures', 'bogus', 7],
            'log'    => ['sortable' => true, 'align_numbers' => true, 'time_format' => 'H:i', 'time_title_format' => 'Y-m-d H:i:s', 'per_page' => 50],
            'header' => ['actions_layout' => 'split', 'status' => true, 'icons' => ['edit' => 'tabler:pencil', 'bogus' => 'x', 'pause' => '', 3 => 'y']],
        ]);

        self::assertSame('host', $layout['charts']);
        self::assertSame(['log' => 'host/_log.html.twig'], $layout['slots']);
        self::assertSame(['figures', 'history', 'details', 'children', 'push', 'charts', 'log'], $layout['order']);
        self::assertSame(['sortable' => true, 'align_numbers' => true, 'time_format' => 'H:i', 'time_title_format' => 'Y-m-d H:i:s', 'per_page' => 50], $layout['log']);
        self::assertSame(['actions_layout' => 'split', 'status' => true, 'icons' => ['edit' => 'tabler:pencil']], $layout['header']);
    }

    public function testInvalidValuesFallBackToTheDefaults(): void
    {
        $layout = MonitorShowLayout::resolve([
            'charts' => 'mine',
            'slots'  => 'nope',
            'order'  => 'figures',
            'log'    => ['sortable' => 'yes', 'time_format' => '', 'time_title_format' => 5, 'per_page' => 0],
            'header' => ['actions_layout' => 'grid', 'status' => 1, 'icons' => 'tabler:pencil'],
        ]);

        self::assertSame('bundle', $layout['charts']);
        self::assertSame([], $layout['slots']);
        self::assertSame([], $layout['order']);
        self::assertSame(['sortable' => false, 'align_numbers' => false, 'time_format' => 'Y-m-d H:i:s', 'time_title_format' => null, 'per_page' => null], $layout['log']);
        self::assertSame(['actions_layout' => 'toolbar', 'status' => false, 'icons' => []], $layout['header']);
        self::assertSame([], MonitorShowLayout::resolve(['log' => 'x', 'header' => 'y'])['header']['icons']);
    }
}
