<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Chart;

use Nowo\UptimeMonitorBundle\Chart\ChartSeriesFormatter;
use Nowo\UptimeMonitorBundle\Twig\ChartSeriesExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(ChartSeriesFormatter::class)]
#[CoversClass(ChartSeriesExtension::class)]
final class ChartSeriesFormatterTest extends TestCase
{
    public function testOneFieldBecomesAChartSeries(): void
    {
        $series = (new ChartSeriesFormatter())->format(
            ['labels' => ['10:00', 11, ['bad'], '12:00'], 'latency_ms' => [12, 13.456, 'x', 20, 99]],
            'latency_ms',
            'latency',
            'Latency',
        );

        self::assertSame([
            'labels' => ['10:00', '11', '12:00'],
            'series' => [['key' => 'latency', 'label' => 'Latency', 'data' => [12, 13.46, null]]],
            'total'  => 2,
        ], $series);
    }

    public function testJsonIsDecodedAndShortDataIsPadded(): void
    {
        $formatter = new ChartSeriesFormatter();

        self::assertSame(
            ['labels' => ['a', 'b'], 'series' => [['key' => 'up', 'label' => 'Uptime', 'data' => [99.9, null]]], 'total' => 1],
            $formatter->format('{"labels":["a","b"],"uptime_percent":[99.94]}', 'uptime_percent', 'up', 'Uptime', 1),
        );
        self::assertSame(
            ['labels' => [], 'series' => [['key' => 'k', 'label' => 'L', 'data' => []]], 'total' => 0],
            $formatter->format('{not json', 'latency_ms', 'k', 'L'),
        );
        self::assertSame(0, $formatter->format(['labels' => ['a'], 'latency_ms' => 'nope'], 'latency_ms', 'k', 'L')['total']);
        self::assertSame(0, $formatter->format('"a string"', 'latency_ms', 'k', 'L')['total']);
    }

    public function testTheTwigFunction(): void
    {
        $twig = new Environment(new ArrayLoader(['t' => '{{ nowo_uptime_chart_series(src, "latency_ms", "l", "Latency").series[0].data|join(",") }}']));
        $twig->addExtension(new ChartSeriesExtension());

        self::assertSame('1,2', $twig->render('t', ['src' => ['labels' => ['a', 'b'], 'latency_ms' => [1, 2]]]));
    }
}
