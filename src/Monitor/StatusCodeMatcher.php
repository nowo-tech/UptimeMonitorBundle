<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Monitor;

use function in_array;

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
                [$min, $max] = array_map('intval', explode('-', $part, 2));
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

    public static function matches(int $statusCode, array $allowed): bool
    {
        return in_array($statusCode, $allowed, true);
    }
}
