<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Push;

use JsonException;

use function array_diff;
use function array_is_list;
use function array_keys;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match;
use function round;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Strict reader of a push body (since 1.14): a JSON object with only known keys, numbers in range and at most
 * {@see self::MAX_DISKS} disks. An empty object is a plain heartbeat. The errors never echo the input.
 *
 * ```json
 * {"cpu_pct": 12.5, "mem_pct": 61, "swap_pct": 0, "load1": 0.4, "load5": 0.3, "load15": 0.2, "cpu_count": 4,
 *  "uptime_s": 86400, "disks": [{"mount": "/", "used_pct": 71.2, "inodes_pct": 9}]}
 * ```
 */
final class PushPayload
{
    public const MAX_DISKS = 32;

    /** @var array<string, array{0: float, 1: float}> metric => [min, max] */
    private const RANGES = [
        'cpu_pct'   => [0.0, 100.0],
        'mem_pct'   => [0.0, 100.0],
        'swap_pct'  => [0.0, 100.0],
        'load1'     => [0.0, 10000.0],
        'load5'     => [0.0, 10000.0],
        'load15'    => [0.0, 10000.0],
        'cpu_count' => [1.0, 4096.0],
        'uptime_s'  => [0.0, 10000000000.0],
    ];

    /** @var array<string, array{0: float, 1: float}> */
    private const DISK_RANGES = [
        'used_pct'   => [0.0, 100.0],
        'inodes_pct' => [0.0, 100.0],
    ];

    /**
     * Parses and validates a push body.
     *
     * @return array{0: array<string, mixed>|null, 1: list<string>} the normalised sample (null when invalid) and the errors
     */
    public static function parse(string $body): array
    {
        try {
            $data = $body === '' ? [] : json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [null, ['body: invalid JSON']];
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return [null, ['body: must be a JSON object']];
        }

        $errors = [];
        $sample = [];
        $known  = [...array_keys(self::RANGES), 'disks'];
        foreach (array_diff(array_keys($data), $known) as $unknown) {
            $errors[] = sprintf('%s: unknown key', is_string($unknown) && preg_match('/^[a-z0-9_]{1,32}$/', $unknown) === 1 ? $unknown : '(key)');
        }
        foreach (self::RANGES as $key => [$min, $max]) {
            if (!isset($data[$key])) {
                continue;
            }
            $value = self::number($data[$key], $min, $max);
            if ($value === null) {
                $errors[] = sprintf('%s: number between %s and %s expected', $key, $min, $max);
                continue;
            }
            $sample[$key] = $key === 'cpu_count' || $key === 'uptime_s' ? (int) $value : round($value, 2);
        }
        if (isset($data['disks'])) {
            [$disks, $diskErrors] = self::disks($data['disks']);
            $errors               = [...$errors, ...$diskErrors];
            $sample['disks']      = $disks;
        }

        return $errors === [] ? [$sample, []] : [null, $errors];
    }

    /**
     * @return array{0: list<array<string, float|string>>, 1: list<string>}
     */
    private static function disks(mixed $disks): array
    {
        if (!is_array($disks) || !array_is_list($disks) || count($disks) > self::MAX_DISKS) {
            return [[], [sprintf('disks: list of at most %d disks expected', self::MAX_DISKS)]];
        }
        $out    = [];
        $errors = [];
        foreach ($disks as $i => $disk) {
            $mount = is_array($disk) ? ($disk['mount'] ?? null) : null;
            // A mount point: printable, no control characters, at most 255 characters.
            if (!is_array($disk) || !is_string($mount) || preg_match('/^[^\p{C}]{1,255}$/u', $mount) !== 1) {
                $errors[] = sprintf('disks[%d].mount: printable string of 1 to 255 characters expected', $i);
                continue;
            }
            $unknown = array_diff(array_keys($disk), ['mount', ...array_keys(self::DISK_RANGES)]);
            if ($unknown !== []) {
                $errors[] = sprintf('disks[%d]: unknown key', $i);
                continue;
            }
            $row = ['mount' => $mount];
            foreach (self::DISK_RANGES as $key => [$min, $max]) {
                if (!isset($disk[$key])) {
                    continue;
                }
                $value = self::number($disk[$key], $min, $max);
                if ($value === null) {
                    $errors[] = sprintf('disks[%d].%s: number between %s and %s expected', $i, $key, $min, $max);
                    continue 2;
                }
                $row[$key] = round($value, 2);
            }
            $out[] = $row;
        }

        return [$out, $errors];
    }

    private static function number(mixed $value, float $min, float $max): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $value = (float) $value;

        return $value >= $min && $value <= $max ? $value : null;
    }
}
