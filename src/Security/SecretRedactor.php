<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

use function array_map;
use function is_array;
use function is_string;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;

/**
 * Masks credentials before monitor data leaves the bundle (dashboard, webhook payloads, alert mails, backups).
 *
 * URL userinfo (`https://user:pass@host`, up to the last `@` of the authority) becomes `https://***@host` and the
 * values of credential-like query parameters (`?token=`, `api_key=`, `secret=`, `sig=`…) become `***`; backups also
 * drop the basic-auth password and the request body and replace custom header values. Monitor managers still see and
 * edit the real values in the monitor form. URLs inside check messages and metadata are masked before they are stored.
 */
final class SecretRedactor
{
    public const MASK = '***';

    /** Query parameter names (case-insensitive substrings) whose values are masked. */
    private const SENSITIVE_QUERY_KEY = '/token|key|secret|pass|pwd|sig|auth|credential|session|code/i';

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
}
