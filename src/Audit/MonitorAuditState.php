<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use BackedEnum;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchUrl;

use function array_keys;
use function array_unique;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function rtrim;
use function strcasecmp;

/**
 * The audited state of a monitor (since 1.33), built from its Doctrine field values, and the diff of two states.
 *
 * The state lives in memory only, for the length of one flush: plain fields hold their (redacted) value, write-only
 * fields hold their raw value only so two states can be compared. Nothing of a write-only value ever leaves this class:
 * {@see self::diff()} turns it into {@see MonitorFieldChange::REPLACED} or {@see MonitorFieldChange::CLEARED}.
 *
 * Plain fields: `name`, `type`, `target` and `proxy` (origin only, {@see MonitorSearchUrl::display()}: an ambiguous URL
 * shows `scheme://***`, an unreadable one nothing), `ignore_tls`, `enabled`, `interval_seconds`, `project`, `parent_id`,
 * `origin`, `origin_key` and the known non-secret config keys ({@see self::PLAIN_CONFIG}). Write-only fields:
 * `target_details` and `proxy_details` (userinfo, path, query and fragment of the URL / proxy), `push_url_key`,
 * `push_token`, the config keys of {@see self::SECRET_CONFIG}, and every other config key as `config.<key>` (fails
 * closed: a key the bundle does not know is never recorded with its value). Internal counters
 * ({@see self::INTERNAL_CONFIG}) and the check state (next check, last status, push samples) are not audited.
 */
final class MonitorAuditState
{
    /** Config keys the checks write as they run: never audited. */
    public const INTERNAL_CONFIG = ['failure_streak', 'down_notify_streak'];

    /** Config keys recorded with their value (route, schedule and alerting settings that hold no credential). */
    public const PLAIN_CONFIG = [
        'method', 'timeout', 'request_timeout_seconds', 'max_redirects', 'expected_status_codes', 'keyword', 'upside_down',
        'check_cert_expiry', 'resend_notification_after_down', 'body_encoding', 'auth_method', 'description', 'tags',
        'host', 'port', 'hostname', 'record_type', 'expected_value', 'days_before_expiry', 'expected_spki_pins',
        'push_signed', 'push_thresholds', 'push_grace_seconds', 'retries', 'retry_interval_seconds', 'min_latency_ms',
    ];

    /** Config keys recorded as write-only (`replaced` / `cleared`). */
    public const SECRET_CONFIG = ['headers', 'body', 'auth_username', 'auth_password'];

    /** Config keys folded into other fields (`target`, `proxy`, `ignore_tls`). */
    private const FOLDED_CONFIG = ['url', 'proxy', 'verify_ssl', 'ignore_tls'];

    /** Longest text value recorded (longer ones are cut, with an ellipsis). */
    public const MAX_TEXT = 200;

    private const PLAIN  = 0;
    private const SECRET = 1;

    /**
     * Builds the state from Doctrine field values of a monitor (`name`, `type`, `target`, `config`, `intervalSeconds`,
     * `paused`, `project`, `parent`, `pushKey`, `pushTokenHash`, `origin`, `originKey`): the current values of an entity
     * or the original data of the unit of work. A missing key reads as no value.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, array{0: int, 1: mixed}> field => [kind, value]
     */
    public static function fromFields(array $fields): array
    {
        $config = is_array($fields['config'] ?? null) ? $fields['config'] : [];
        $type   = $fields['type'] ?? null;
        $state  = [
            'name' => [self::PLAIN, self::plain($fields['name'] ?? null)],
            'type' => [self::PLAIN, self::plain($type)],
        ];

        if ($type !== MonitorType::Group) {
            // An HTTP monitor stores its URL in the config as well; the config one is what the check requests.
            $target = is_string($config['url'] ?? null) ? $config['url'] : ($fields['target'] ?? null);
            $state += self::url('target', is_string($target) ? $target : null);
        }
        $state += self::url('proxy', is_string($config['proxy'] ?? null) ? $config['proxy'] : null);
        $state['ignore_tls']       = [self::PLAIN, ($config['verify_ssl'] ?? true) === false || ($config['ignore_tls'] ?? false) === true];
        $state['enabled']          = [self::PLAIN, !(($fields['paused'] ?? false) === true)];
        $state['interval_seconds'] = [self::PLAIN, self::plain($fields['intervalSeconds'] ?? null)];
        $state['project']          = [self::PLAIN, self::plain($fields['project'] ?? null)];
        $parent                    = $fields['parent'] ?? null;
        $state['parent_id']        = [self::PLAIN, $parent instanceof Monitor ? $parent->getId() : null];
        $state['origin']           = [self::PLAIN, self::plain($fields['origin'] ?? null)];
        $state['origin_key']       = [self::PLAIN, self::plain($fields['originKey'] ?? null)];
        $state['push_url_key']     = [self::SECRET, $fields['pushKey'] ?? null];
        $state['push_token']       = [self::SECRET, $fields['pushTokenHash'] ?? null];

        foreach ($config as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::INTERNAL_CONFIG, true) || in_array($key, self::FOLDED_CONFIG, true)) {
                continue;
            }
            if (in_array($key, self::PLAIN_CONFIG, true)) {
                $state[$key] = [self::PLAIN, self::plain($value)];
            } elseif (in_array($key, self::SECRET_CONFIG, true)) {
                $state[$key] = [self::SECRET, $value];
            } else {
                $state['config.' . $key] = [self::SECRET, $value];
            }
        }

        return $state;
    }

    /**
     * The field changes from one state to another (an empty state for a monitor that did not exist / no longer does),
     * in the order of the fields of `$after`, then the ones only `$before` had.
     *
     * @param array<string, array{0: int, 1: mixed}> $before
     * @param array<string, array{0: int, 1: mixed}> $after
     *
     * @return list<MonitorFieldChange>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($after), ...array_keys($before)]) as $field) {
            [$kindBefore, $valueBefore] = $before[$field] ?? [self::PLAIN, null];
            [$kindAfter, $valueAfter]   = $after[$field] ?? [$kindBefore, null];
            if ($kindBefore === self::SECRET || $kindAfter === self::SECRET) {
                $emptyBefore = self::isEmpty($valueBefore);
                $emptyAfter  = self::isEmpty($valueAfter);
                if (($emptyBefore && $emptyAfter) || $valueBefore === $valueAfter) {
                    continue;
                }
                $changes[] = MonitorFieldChange::secret((string) $field, $emptyAfter);
                continue;
            }
            if ($valueBefore !== $valueAfter) {
                $changes[] = new MonitorFieldChange((string) $field, $valueBefore, $valueAfter);
            }
        }

        return $changes;
    }

    /**
     * The origin of a URL or host (plain) and the rest of it (write-only): userinfo, path, query and fragment.
     *
     * @return array<string, array{0: int, 1: mixed}>
     */
    private static function url(string $field, ?string $value): array
    {
        if ($value === null || $value === '') {
            return [$field => [self::PLAIN, null], $field . '_details' => [self::SECRET, null]];
        }

        $origin  = MonitorSearchUrl::display($value);
        $details = $origin !== '' && (strcasecmp($value, $origin) === 0 || strcasecmp(rtrim($value, '/'), $origin) === 0) ? null : $value;

        return [$field => [self::PLAIN, $origin !== '' ? $origin : null], $field . '_details' => [self::SECRET, $details]];
    }

    /**
     * A plain value as recorded: scalars as they are (text cut at {@see self::MAX_TEXT} characters), enums by value,
     * lists and maps of those; anything else as null.
     */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            is_string($value)                                 => mb_strlen($value) > self::MAX_TEXT ? mb_substr($value, 0, self::MAX_TEXT) . '…' : $value,
            $value instanceof BackedEnum                      => $value->value,
            is_int($value), is_float($value), is_bool($value) => $value,
            is_array($value)                                  => self::plainArray($value),
            default                                           => null,
        };
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function plainArray(array $value): array
    {
        $plain = [];
        foreach ($value as $key => $item) {
            $plain[$key] = self::plain($item);
        }

        return $plain;
    }

    /**
     * Whether a write-only value counts as none (null, empty string, empty list).
     */
    private static function isEmpty(mixed $value): bool
    {
        return in_array($value, [null, '', []], true);
    }
}
