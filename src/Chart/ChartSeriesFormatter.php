<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Chart;

use JsonException;

use function array_filter;
use function array_map;
use function array_pad;
use function array_slice;
use function array_values;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function json_decode;
use function round;

use const JSON_THROW_ON_ERROR;

/**
 * Chart-ready series of the bundle's history data (since 1.35): one field of a series payload (the monitor page's
 * `chart_series_json`, a `latency_series`, or the history API's series, all `{labels: [...], <field>: [...]}`) as
 * `{labels, series: [{key, label, data}], total}`, the shape of a generic chart card. `total` counts the points that
 * have a value (0 = show an empty state). Integers stay integers, decimals are rounded, anything else is a gap (null);
 * the data is padded or cut to the labels. Also the Twig function `nowo_uptime_chart_series()`. Stateless.
 */
final class ChartSeriesFormatter
{
    /**
     * @param array<string, mixed>|string $source The series payload, or its JSON (invalid JSON = no data)
     * @param string $field The field of the payload to chart (e.g. `latency_ms`, `uptime_percent`)
     * @param string $key Series key of the chart
     * @param string $label Series label of the chart (already translated)
     * @param int $precision Decimal places kept for decimals
     *
     * @return array{labels: list<string>, series: list<array{key: string, label: string, data: list<float|int|null>}>, total: int}
     */
    public function format(array|string $source, string $field, string $key, string $label, int $precision = 2): array
    {
        if (is_string($source)) {
            try {
                $decoded = json_decode($source, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }
            $source = is_array($decoded) ? $decoded : [];
        }

        $labels = array_values(array_map(
            static fn (bool|float|int|string $label): string => (string) $label,
            array_filter(is_array($source['labels'] ?? null) ? $source['labels'] : [], is_scalar(...)),
        ));
        $data = [];
        foreach (is_array($source[$field] ?? null) ? array_values($source[$field]) : [] as $value) {
            $data[] = match (true) {
                is_int($value)   => $value,
                is_float($value) => round($value, $precision),
                default          => null,
            };
        }
        $data = array_slice(array_pad($data, count($labels), null), 0, count($labels));

        return [
            'labels' => $labels,
            'series' => [['key' => $key, 'label' => $label, 'data' => $data]],
            'total'  => count(array_filter($data, static fn (float|int|null $value): bool => $value !== null)),
        ];
    }
}
