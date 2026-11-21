<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Query;

use DateTimeImmutable;
use DateTimeInterface;

use function is_numeric;
use function is_string;

/**
 * Scalar conversions of the report read rows (since 1.36): drivers hand back integers, numeric strings or floats, and
 * dates as objects or strings, depending on the platform and the hydration.
 *
 * @internal
 */
final class ReadRow
{
    /**
     * An integer; 0 when the value is not numeric (a SUM over no row is NULL).
     */
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * A string; '' when the value is neither a string nor a number.
     */
    public static function string(mixed $value): string
    {
        return is_string($value) ? $value : (is_numeric($value) ? (string) $value : '');
    }

    /**
     * A date (objects as they are, strings parsed); null for NULL, '' and anything else.
     */
    public static function date(mixed $value): ?DateTimeImmutable
    {
        return match (true) {
            $value instanceof DateTimeImmutable => $value,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value),
            is_string($value) && $value !== ''  => new DateTimeImmutable($value),
            default                             => null,
        };
    }
}
