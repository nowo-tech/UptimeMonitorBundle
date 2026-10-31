<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Monitor;

use function count;
use function ctype_digit;
use function in_array;
use function is_int;
use function is_string;
use function sort;

/**
 * Parses Uptime Kuma style status code lists (200, 201, 200-299).
 */
final class StatusCodeMatcher
{
    /**
     * @return list<int>
     */
    public static function parse(string $input): array
    {
        $codes = [];
        foreach (explode(',', $input) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, '-')) {
                [$min, $max] = array_map(intval(...), explode('-', $part, 2));
                if ($min > $max) {
                    [$min, $max] = [$max, $min];
                }
                for ($code = $min; $code <= $max; ++$code) {
                    $codes[] = $code;
                }
                continue;
            }

            $codes[] = (int) $part;
        }

        $codes = array_values(array_unique(array_filter($codes, static fn (int $c): bool => $c >= 100 && $c <= 599)));

        return $codes === [] ? [200] : $codes;
    }

    /**
     * The compact form of a code list, the inverse of {@see parse()}: runs of three or more consecutive codes become a
     * range (`200, 201, …, 299` → `200-299`; `200, 201, 204` → `200,201,204`). Numeric strings count as codes (backups
     * of older versions); any other entry is skipped.
     *
     * @param array<mixed> $codes
     */
    public static function format(array $codes): string
    {
        $codes = array_map(intval(...), array_filter($codes, static fn (mixed $code): bool => is_int($code) || (is_string($code) && ctype_digit($code))));
        $codes = array_values(array_unique($codes));
        sort($codes);

        $parts = [];
        $count = count($codes);
        for ($start = 0; $start < $count; $start = $end + 1) {
            $end = $start;
            while ($end + 1 < $count && $codes[$end + 1] === $codes[$end] + 1) {
                ++$end;
            }
            if ($end - $start >= 2) {
                $parts[] = $codes[$start] . '-' . $codes[$end];
                continue;
            }
            for ($i = $start; $i <= $end; ++$i) {
                $parts[] = (string) $codes[$i];
            }
        }

        return implode(',', $parts);
    }

    /**
     * @param list<int> $allowed
     */
    public static function matches(int $statusCode, array $allowed): bool
    {
        return in_array($statusCode, $allowed, true);
    }
}
