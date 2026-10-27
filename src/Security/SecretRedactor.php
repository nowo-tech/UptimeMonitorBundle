<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

use function array_map;
use function is_array;
use function is_string;
use function preg_replace;

/**
 * Masks credentials before monitor data leaves the bundle (dashboard, webhook payloads, alert mails, backups).
 *
 * URL userinfo (`https://user:pass@host`) becomes `https://***@host`; backups also drop the basic-auth password and
 * replace custom header values. Monitor managers still see and edit the real values in the monitor form.
 */
final class SecretRedactor
{
    public const MASK = '***';

    /**
     * Replaces the userinfo of a URL (`scheme://user:pass@`) with `***`; other strings are returned unchanged.
     */
    public static function maskUrl(string $value): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.\-]*://)[^/?\#@]*@#i', '$1' . self::MASK . '@', $value);
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

        if (isset($config['auth_password'])) {
            $config['auth_password'] = '';
        }

        if (isset($config['headers']) && is_array($config['headers'])) {
            $config['headers'] = array_map(static fn (mixed $value): string => self::MASK, $config['headers']);
        }

        return $config;
    }
}
