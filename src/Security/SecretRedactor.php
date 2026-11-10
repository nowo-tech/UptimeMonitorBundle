<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_shift;
use function is_array;
use function is_string;
use function parse_url;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
use function strtolower;

use const PREG_SET_ORDER;

/**
 * Masks credentials before monitor data leaves the bundle (dashboard, webhook payloads, alert mails, backups).
 *
 * URL userinfo (`https://user:pass@host`, up to the last `@` of the authority) becomes `https://***@host` and the
 * values of credential-like query parameters (`?token=`, `api_key=`, `secret=`, `sig=`…) become `***`; backups also
 * drop the basic-auth password and the request body and replace custom header values. URLs inside check messages and
 * metadata are masked before they are stored.
 *
 * The monitor edit form is write-only for the same secrets (since 1.25.1): {@see self::maskEditableUrl()} and
 * {@see self::maskEditableHeaders()} put {@see self::MASK} in their place, and {@see self::restoreEditableUrl()} /
 * {@see self::restoreEditableHeaders()} put the stored value back where the submitted form still holds the mask.
 * A mask with nothing stored behind it is never saved (since 1.25.2): the form asks for the value, and
 * {@see self::stripMaskedUrl()} / {@see self::stripMaskedHeaders()} drop it everywhere else.
 */
final class SecretRedactor
{
    public const MASK = '***';

    /**
     * Query parameter names (case-insensitive substrings) whose values are masked; `jwt`, `bearer` and `hash` since
     * 1.25.2 (`signature` is matched by `sig`). Masking is by name only: a secret in the URL path (a Slack webhook
     * `/services/…`, a Telegram `/bot<token>/`) or under another name is not masked.
     */
    private const SENSITIVE_QUERY_KEY = '/token|key|secret|pass|pwd|sig|auth|credential|session|code|jwt|bearer|hash/i';

    /** The userinfo of a URL, with or without a scheme (`http://user:pass@` or `user:pass@`), up to its last `@`. */
    private const EDITABLE_USERINFO = '#^((?:[a-z][a-z0-9+.\-]*://)?)([^/?\#]*)@#i';

    /** Default port by scheme, so `https://host` and `https://host:443` are one destination. */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /** One `name=value` pair of a query string. */
    private const QUERY_PAIR = '#([?&])([^=&\#]*)=([^&\#]*)#';

    /**
     * Masks the userinfo of a URL (`scheme://user:pass@`, up to the last `@` before the path) and the values of
     * credential-like query parameters; strings without a scheme are returned unchanged.
     */
    public static function maskUrl(string $value): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value) !== 1) {
            return $value;
        }

        // Greedy authority match: a raw '@' inside the password is part of the userinfo too.
        $value = (string) preg_replace('#^([a-z][a-z0-9+.\-]*://)[^/?\#]*@#i', '$1' . self::MASK . '@', $value);

        return (string) preg_replace_callback(
            '#([?&])([^=&\#]*)=([^&\#]*)#',
            static fn (array $m): string => $m[1] . $m[2] . '=' . (preg_match(self::SENSITIVE_QUERY_KEY, $m[2]) === 1 ? self::MASK : $m[3]),
            $value,
        );
    }

    /**
     * Masks every URL found in free text (check messages, transport errors: SEC-004 I3) with {@see self::maskUrl()};
     * the rest of the text is unchanged.
     */
    public static function maskUrlsInText(string $text): string
    {
        return (string) preg_replace_callback(
            '#[a-z][a-z0-9+.\-]*://[^\s"\'<>]+#i',
            static fn (array $m): string => self::maskUrl($m[0]),
            $text,
        );
    }

    /**
     * Redacts the secrets of a monitor config for export: URL / proxy userinfo, the basic-auth password and every
     * custom header value.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function redactMonitorConfig(array $config): array
    {
        foreach (['url', 'proxy'] as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                $config[$key] = self::maskUrl($config[$key]);
            }
        }

        // A proxy may be written without a scheme (`user:pass@proxy:3128`): its userinfo is masked too (since 1.25.1).
        if (isset($config['proxy']) && is_string($config['proxy'])) {
            $config['proxy'] = self::maskEditableUrl($config['proxy']);
        }

        foreach (['auth_password', 'body'] as $key) {
            if (isset($config[$key])) {
                $config[$key] = '';
            }
        }

        if (isset($config['headers']) && is_array($config['headers'])) {
            $config['headers'] = array_map(static fn (mixed $value): string => self::MASK, $config['headers']);
        }

        return $config;
    }

    /**
     * The value the monitor edit form shows for a stored URL or proxy (since 1.25.1): a non-empty userinfo (with or
     * without a scheme) and the non-empty values of credential-like query parameters become {@see self::MASK}.
     */
    public static function maskEditableUrl(string $value): string
    {
        $value = (string) preg_replace_callback(
            self::EDITABLE_USERINFO,
            static fn (array $m): string => $m[1] . ($m[2] === '' ? '' : self::MASK) . '@',
            $value,
        );

        return (string) preg_replace_callback(
            self::QUERY_PAIR,
            static fn (array $m): string => $m[1] . $m[2] . '=' . ($m[3] !== '' && preg_match(self::SENSITIVE_QUERY_KEY, $m[2]) === 1 ? self::MASK : $m[3]),
            $value,
        );
    }

    /**
     * The URL or proxy to store from the edit form (since 1.25.1): where the submitted value still holds the mask of
     * {@see self::maskEditableUrl()}, the stored userinfo and the stored values of the same credential-like query
     * parameters (in order) are put back. A typed value replaces the stored one; a URL without userinfo or parameter
     * removes it. A mask with no stored value behind it stays as typed ({@see self::stripMaskedUrl()} drops it).
     */
    public static function restoreEditableUrl(string $submitted, string $stored): string
    {
        if ($stored === '' || !str_contains($submitted, self::MASK)) {
            return $submitted;
        }

        // The stored values of the masked parameters, by name and in order (read with the userinfo masked, as shown).
        $storedPairs = [];
        preg_match_all(self::QUERY_PAIR, self::maskUserinfo($stored), $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            if ($pair[3] !== '' && preg_match(self::SENSITIVE_QUERY_KEY, $pair[2]) === 1) {
                $storedPairs[$pair[2]][] = $pair[3];
            }
        }

        $submitted = (string) preg_replace_callback(
            self::QUERY_PAIR,
            static function (array $m) use (&$storedPairs): string {
                if ($m[3] !== self::MASK || !isset($storedPairs[$m[2]]) || $storedPairs[$m[2]] === []) {
                    return $m[0];
                }

                return $m[1] . $m[2] . '=' . array_shift($storedPairs[$m[2]]);
            },
            $submitted,
        );

        if (preg_match(self::EDITABLE_USERINFO, $stored, $userinfo) !== 1 || $userinfo[2] === '') {
            return $submitted;
        }

        return (string) preg_replace_callback(
            self::EDITABLE_USERINFO,
            static fn (array $m): string => $m[1] . ($m[2] === self::MASK ? $userinfo[2] : $m[2]) . '@',
            $submitted,
        );
    }

    /**
     * The header values the monitor edit form shows (since 1.25.1): every non-empty value becomes {@see self::MASK},
     * as on the monitor page and in backups; the names stay.
     *
     * @param array<mixed> $headers
     *
     * @return array<string, string>
     */
    public static function maskEditableHeaders(array $headers): array
    {
        $masked = [];
        foreach ($headers as $name => $value) {
            $masked[(string) $name] = is_string($value) && $value === '' ? '' : self::MASK;
        }

        return $masked;
    }

    /**
     * The headers to store from the edit form (since 1.25.1): a header whose submitted value is still the mask keeps
     * the stored value of the header with the same name (case-insensitive). A typed value replaces it; a removed line
     * removes the header. A mask with no stored header behind it stays as typed ({@see self::stripMaskedHeaders()}
     * drops it).
     *
     * @param array<string, string> $submitted
     *
     * @return array<string, string>
     */
    public static function restoreEditableHeaders(array $submitted, mixed $stored): array
    {
        if (!is_array($stored)) {
            return $submitted;
        }

        $byName = [];
        foreach ($stored as $name => $value) {
            if (is_string($value)) {
                $byName[strtolower((string) $name)] = $value;
            }
        }

        foreach ($submitted as $name => $value) {
            if ($value === self::MASK && array_key_exists(strtolower($name), $byName)) {
                $submitted[$name] = $byName[strtolower($name)];
            }
        }

        return $submitted;
    }

    /**
     * The URL or proxy without the masks left in it (since 1.25.2): a userinfo that is exactly {@see self::MASK} and
     * every query parameter whose value is exactly the mask are removed, so a mask with nothing stored behind it is
     * never sent as the literal `***`.
     */
    public static function stripMaskedUrl(string $value): string
    {
        if (!str_contains($value, self::MASK)) {
            return $value;
        }

        $value = (string) preg_replace_callback(
            self::EDITABLE_USERINFO,
            static fn (array $m): string => $m[2] === self::MASK ? $m[1] : $m[0],
            $value,
        );

        // A removed pair keeps its separator, marked with NUL, then the separators are tidied up.
        $value = (string) preg_replace_callback(
            self::QUERY_PAIR,
            static fn (array $m): string => $m[3] === self::MASK ? $m[1] . "\0" : $m[0],
            $value,
        );

        return (string) preg_replace(['#&\x00#', '#\?\x00&#', '#\?\x00#'], ['', '?', ''], $value);
    }

    /**
     * The headers without those whose value is exactly {@see self::MASK} (since 1.25.2).
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public static function stripMaskedHeaders(array $headers): array
    {
        return array_filter($headers, static fn (string $value): bool => $value !== self::MASK);
    }

    /**
     * Whether two URLs (or proxies, a missing scheme read as `http://`) reach the same destination: same scheme, host
     * and port, the default port of http / https filled in, case-insensitive (since 1.25.1). A kept secret is only put
     * back for the same destination. A value that does not parse matches only the identical value.
     */
    public static function sameOrigin(string $a, string $b): bool
    {
        return self::origin($a) === self::origin($b);
    }

    private static function origin(string $value): string
    {
        $url = self::maskUserinfo($value);
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) !== 1) {
            $url = 'http://' . $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return "\0" . $value;
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $port   = $parts['port'] ?? self::DEFAULT_PORTS[$scheme] ?? 0;

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    private static function maskUserinfo(string $value): string
    {
        return (string) preg_replace(self::EDITABLE_USERINFO, '$1' . self::MASK . '@', $value);
    }
}
