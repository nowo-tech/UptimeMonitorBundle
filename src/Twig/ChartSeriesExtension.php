<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Twig;

use Nowo\UptimeMonitorBundle\Chart\ChartSeriesFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig function `nowo_uptime_chart_series(source, field, key, label, precision = 2)` (since 1.35): a chart-ready
 * series of the bundle's history data ({@see ChartSeriesFormatter}). Stateless.
 */
final class ChartSeriesExtension extends AbstractExtension
{
    /**
     * @param ChartSeriesFormatter $formatter The formatter behind the function
     */
    public function __construct(
        private readonly ChartSeriesFormatter $formatter = new ChartSeriesFormatter(),
    ) {
    }

    /**
     * Registers `nowo_uptime_chart_series()`.
     *
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_uptime_chart_series', $this->formatter->format(...)),
        ];
    }
}
