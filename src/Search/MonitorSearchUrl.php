<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

use function is_array;
use function parse_url;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strtolower;

/**
 * What a search result shows of a monitor target (since 1.32): the origin only, `scheme://host[:port]` for a URL or
 * `host[:port]` for a bare host. Userinfo, path, query string and fragment are dropped, so no credential written into
 * a URL (`user:pass@`, `?token=`, a bot token in the path) reaches a result or a match. Matching uses this same value.
 */
final class MonitorSearchUrl
{
    /**
     * The displayable origin of a stored target; an empty string when it cannot be read as a host.
     */
    public static function display(string $target): string
    {
        $target    = (string) preg_replace('#^([^/?\\#]*)@#', '', (string) preg_replace('#^([a-z][a-z0-9+.\-]*://)[^/?\\#]*@#i', '$1', $target));
        $hasScheme = preg_match('#^[a-z][a-z0-9+.\-]*://#i', $target) === 1;

        $parts = parse_url($hasScheme ? $target : 'tcp://' . $target);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return '';
        }

        $origin = strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $hasScheme ? sprintf('%s://%s', strtolower($parts['scheme'] ?? ''), $origin) : $origin;
    }
}
