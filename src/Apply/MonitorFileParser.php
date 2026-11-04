<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

use JsonException;
use Nowo\UptimeMonitorBundle\Push\PushThresholds;
use stdClass;

use function array_key_exists;
use function array_keys;
use function clearstatcache;
use function count;
use function file_get_contents;
use function filesize;
use function get_object_vars;
use function hash;
use function in_array;
use function is_array;
use function is_bool;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function mb_strlen;
use function preg_match;
use function str_replace;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Reads and validates a monitors file of `nowo:uptime:apply-monitors` (schema v1, since 1.18;
 * `Resources/schema/apply-monitors.v1.json`). Every error is collected before the run is refused (exit 3), each one
 * a JSON pointer and a code: values are never echoed. Unknown keys are refused, not ignored.
 */
final readonly class MonitorFileParser
{
    public const MAX_BYTES        = 1_048_576;
    public const MAX_TENANTS      = 50;
    public const MAX_MONITORS     = 200;
    public const TENANT_PATTERN   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    public const KEY_PATTERN      = '/^[a-z0-9][a-z0-9._-]{0,63}$/';
    public const URL_KEY_PATTERN  = '/^[a-f0-9]{32}$/';
    private const ROOT_KEYS       = ['$schema', 'version', 'tenants'];
    private const MONITOR_KEYS    = ['key', 'type', 'name', 'project', 'description', 'url_key', 'interval_seconds', 'grace_seconds', 'retries', 'resend_notification_after_down', 'paused', 'thresholds'];
    private const REQUIRED_KEYS   = ['key', 'type', 'name', 'url_key', 'interval_seconds'];
    private const PERCENT_METRICS = ['disk', 'inodes', 'memory', 'cpu', 'swap'];

    /**
     * Reads the file (a regular file of at most {@see self::MAX_BYTES}) and validates it.
     *
     * @throws ApplyRefusedException exit 3 with every error found
     */
    public function parseFile(string $path): MonitorFileSpec
    {
        if (!is_file($path)) {
            throw $this->refuse([new ApplyError('', 'file_not_found', ApplyError::EXIT_FILE)]);
        }
        clearstatcache(true, $path);
        if (filesize($path) > self::MAX_BYTES) {
            throw $this->refuse([new ApplyError('', 'file_too_large', ApplyError::EXIT_FILE)]);
        }

        return $this->parse((string) @file_get_contents($path));
    }

    /**
     * Validates the JSON text of a monitors file.
     *
     * @throws ApplyRefusedException exit 3 with every error found
     */
    public function parse(string $json): MonitorFileSpec
    {
        try {
            $root = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->refuse([new ApplyError('', 'invalid_json', ApplyError::EXIT_FILE)]);
        }

        $errors  = [];
        $tenants = $this->root($root, $errors);
        if ($errors !== []) {
            throw $this->refuse($errors);
        }

        return new MonitorFileSpec($tenants, hash('sha256', $json));
    }

    /**
     * @param list<ApplyError> $errors
     *
     * @return array<string, list<PushMonitorSpec>>
     */
    private function root(mixed $root, array &$errors): array
    {
        if (!$root instanceof stdClass) {
            $errors[] = $this->error('', 'invalid_type');

            return [];
        }
        $fields = get_object_vars($root);
        $this->unknownKeys($fields, self::ROOT_KEYS, '', $errors);
        if (array_key_exists('$schema', $fields) && !is_string($fields['$schema'])) {
            $errors[] = $this->error('/$schema', 'invalid_type');
        }
        if (!array_key_exists('version', $fields)) {
            $errors[] = $this->error('/version', 'missing_key');
        } elseif ($fields['version'] !== 1) {
            $errors[] = $this->error('/version', 'unsupported_version');
        }

        $tenantMap = $fields['tenants'] ?? null;
        if (!$tenantMap instanceof stdClass) {
            $errors[] = $this->error('/tenants', $tenantMap === null ? 'missing_key' : 'invalid_type');

            return [];
        }
        $list = get_object_vars($tenantMap);
        if ($list === [] || count($list) > self::MAX_TENANTS) {
            $errors[] = $this->error('/tenants', $list === [] ? 'empty' : 'too_many');

            return [];
        }

        $tenants = [];
        $keys    = [];
        $urlKeys = [];
        foreach ($list as $slug => $tenant) {
            $slug = (string) $slug;
            $path = '/tenants/' . $this->pointer($slug);
            if (preg_match(self::TENANT_PATTERN, $slug) !== 1) {
                $errors[] = $this->error($path, 'invalid_tenant');
                continue;
            }
            $tenants[$slug] = $this->tenant($slug, $tenant, $path, $keys, $urlKeys, $errors);
        }

        return $tenants;
    }

    /**
     * @param array<string, true> $keys entry keys seen so far (unique across the file: the token map is keyed by them)
     * @param array<string, true> $urlKeys ingest URL keys seen so far
     * @param list<ApplyError> $errors
     *
     * @return list<PushMonitorSpec>
     */
    private function tenant(string $slug, mixed $tenant, string $path, array &$keys, array &$urlKeys, array &$errors): array
    {
        if (!$tenant instanceof stdClass) {
            $errors[] = $this->error($path, 'invalid_type');

            return [];
        }
        $fields = get_object_vars($tenant);
        $this->unknownKeys($fields, ['monitors'], $path, $errors);
        $monitors = $fields['monitors'] ?? null;
        if (!is_array($monitors)) {
            $errors[] = $this->error($path . '/monitors', $monitors === null ? 'missing_key' : 'invalid_type');

            return [];
        }
        if (count($monitors) > self::MAX_MONITORS) {
            $errors[] = $this->error($path . '/monitors', 'too_many');

            return [];
        }

        $specs = [];
        foreach ($monitors as $index => $monitor) {
            $spec = $this->monitor($slug, (int) $index, $monitor, $path . '/monitors/' . $index, $errors);
            if (!$spec instanceof PushMonitorSpec) {
                continue;
            }
            if (isset($keys[$spec->key])) {
                $errors[] = $this->error($spec->path('key'), 'duplicate_key');
            }
            if (isset($urlKeys[$spec->urlKey])) {
                $errors[] = $this->error($spec->path('url_key'), 'duplicate_url_key');
            }
            $keys[$spec->key]       = true;
            $urlKeys[$spec->urlKey] = true;
            $specs[]                = $spec;
        }

        return $specs;
    }

    /**
     * @param list<ApplyError> $errors
     */
    private function monitor(string $slug, int $index, mixed $monitor, string $path, array &$errors): ?PushMonitorSpec
    {
        if (!$monitor instanceof stdClass) {
            $errors[] = $this->error($path, 'invalid_type');

            return null;
        }
        $fields = get_object_vars($monitor);
        $before = count($errors);
        $this->unknownKeys($fields, self::MONITOR_KEYS, $path, $errors);
        foreach (self::REQUIRED_KEYS as $required) {
            if (!array_key_exists($required, $fields)) {
                $errors[] = $this->error($path . '/' . $required, 'missing_key');
            }
        }

        $key         = $this->pattern($fields, 'key', self::KEY_PATTERN, $path, $errors);
        $urlKey      = $this->pattern($fields, 'url_key', self::URL_KEY_PATTERN, $path, $errors);
        $name        = $this->text($fields, 'name', 255, $path, $errors);
        $project     = $this->text($fields, 'project', 128, $path, $errors);
        $description = $this->text($fields, 'description', 2000, $path, $errors);
        if (array_key_exists('type', $fields) && $fields['type'] !== 'push') {
            $errors[] = $this->error($path . '/type', 'unsupported_type');
        }
        if ($name !== null && trim($name) === '') {
            $errors[] = $this->error($path . '/name', 'empty');
        }
        $interval = $this->integer($fields, 'interval_seconds', 30, 31_536_000, $path, $errors);
        $grace    = $fields['grace_seconds'] ?? null;
        $grace    = $grace === null ? null : $this->integer($fields, 'grace_seconds', 0, 86_400, $path, $errors);
        $retries  = $this->integer($fields, 'retries', 0, 100, $path, $errors) ?? 0;
        $resend   = $this->integer($fields, 'resend_notification_after_down', 0, 10_000, $path, $errors) ?? 0;
        $paused   = $fields['paused'] ?? null;
        if ($paused !== null && !is_bool($paused)) {
            $errors[] = $this->error($path . '/paused', 'invalid_type');
        }
        $thresholds = $this->thresholds($fields['thresholds'] ?? null, $path . '/thresholds', $errors);

        if (count($errors) !== $before || $key === null || $urlKey === null || $name === null || $interval === null) {
            return null;
        }

        return new PushMonitorSpec($slug, $index, $key, $name, $project ?? '', $description ?? '', $urlKey, $interval, $grace, $retries, $resend, is_bool($paused) ? $paused : null, $thresholds);
    }

    /**
     * Every metric of {@see PushThresholds::DEFAULTS}; a metric absent from the file keeps the default.
     *
     * @param list<ApplyError> $errors
     *
     * @return array<string, array{0: float|null, 1: float|null}>
     */
    private function thresholds(mixed $value, string $path, array &$errors): array
    {
        $limits = PushThresholds::DEFAULTS;
        if ($value === null) {
            return $limits;
        }
        if (!$value instanceof stdClass) {
            $errors[] = $this->error($path, 'invalid_type');

            return $limits;
        }
        $metrics = get_object_vars($value);
        $this->unknownKeys($metrics, array_keys(PushThresholds::DEFAULTS), $path, $errors);
        foreach ($metrics as $metric => $pair) {
            if (!array_key_exists($metric, $limits)) {
                continue;
            }
            $limits[$metric] = $this->limitPair((string) $metric, $pair, $path . '/' . $metric, $errors);
        }

        return $limits;
    }

    /**
     * @param list<ApplyError> $errors
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function limitPair(string $metric, mixed $pair, string $path, array &$errors): array
    {
        if (!$pair instanceof stdClass) {
            $errors[] = $this->error($path, 'invalid_type');

            return PushThresholds::DEFAULTS[$metric];
        }
        $fields = get_object_vars($pair);
        $this->unknownKeys($fields, ['warn', 'critical'], $path, $errors);
        $max    = in_array($metric, self::PERCENT_METRICS, true) ? 100.0 : 10_000.0;
        $values = [];
        foreach (['warn', 'critical'] as $level) {
            if (!array_key_exists($level, $fields)) {
                $errors[] = $this->error($path . '/' . $level, 'missing_key');
                $values[] = null;
                continue;
            }
            $limit = $fields[$level];
            if ($limit !== null && !is_int($limit) && !is_float($limit)) {
                $errors[] = $this->error($path . '/' . $level, 'invalid_type');
                $limit    = null;
            } elseif ($limit !== null && ($limit < 0 || $limit > $max)) {
                $errors[] = $this->error($path . '/' . $level, 'out_of_range');
            }
            $values[] = $limit === null ? null : (float) $limit;
        }
        if ($values[0] !== null && $values[1] !== null && $values[0] > $values[1]) {
            $errors[] = $this->error($path, 'warn_above_critical');
        }

        return [$values[0], $values[1]];
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<ApplyError> $errors
     */
    private function pattern(array $fields, string $key, string $pattern, string $path, array &$errors): ?string
    {
        $value = $fields[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            $errors[] = $this->error($path . '/' . $key, is_string($value) ? 'invalid_format' : 'invalid_type');

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<ApplyError> $errors
     */
    private function text(array $fields, string $key, int $maxLength, string $path, array &$errors): ?string
    {
        $value = $fields[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            $errors[] = $this->error($path . '/' . $key, is_string($value) ? 'too_long' : 'invalid_type');

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<ApplyError> $errors
     */
    private function integer(array $fields, string $key, int $min, int $max, string $path, array &$errors): ?int
    {
        $value = $fields[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            $errors[] = $this->error($path . '/' . $key, is_int($value) ? 'out_of_range' : 'invalid_type');

            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param list<string> $allowed
     * @param list<ApplyError> $errors
     */
    private function unknownKeys(array $fields, array $allowed, string $path, array &$errors): void
    {
        foreach ($fields as $key => $_) {
            if (!in_array((string) $key, $allowed, true)) {
                $errors[] = $this->error($path . '/' . $this->pointer((string) $key), 'unknown_key');
            }
        }
    }

    /**
     * A JSON pointer segment (RFC 6901 escaping), cut at 64 characters.
     */
    private function pointer(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], substr($segment, 0, 64));
    }

    private function error(string $path, string $code): ApplyError
    {
        return new ApplyError($path, $code, ApplyError::EXIT_FILE);
    }

    /**
     * @param list<ApplyError> $errors
     */
    private function refuse(array $errors): ApplyRefusedException
    {
        return new ApplyRefusedException($errors);
    }
}
