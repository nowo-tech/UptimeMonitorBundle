<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

use function filter_var;
use function gethostbyname;
use function is_string;
use function sprintf;
use function str_starts_with;
use function strtolower;

use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;
use const PHP_URL_HOST;

/**
 * Blocks monitor URLs that target private/local networks (SSRF mitigation).
 */
final class MonitorUrlSsrfGuard
{
    public function isBlocked(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return true;
        }

        $host      = trim($host, '[]');
        $hostLower = strtolower($host);

        if ($hostLower === 'localhost'
            || $hostLower === '::1'
            || str_starts_with($hostLower, 'fe80:')
            || $hostLower === 'metadata.google.internal'
        ) {
            return true;
        }

        $ip = $host;
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $resolved = gethostbyname($host);
            if ($resolved === $host) {
                return false;
            }
            $ip = $resolved;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }
            $u = (float) sprintf('%u', $long);

            return ($u >= 2130706432 && $u <= 2147483647)
                || ($u >= 167772160 && $u <= 184549375)
                || ($u >= 2886729728 && $u <= 2887778303)
                || ($u >= 3232235520 && $u <= 3232301055)
                || ($u >= 2851995648 && $u <= 2852061183);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return str_starts_with($ip, '::1') || str_starts_with($ip, 'fe80:');
        }

        return false;
    }
}
