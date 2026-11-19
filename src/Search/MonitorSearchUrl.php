<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

use Nowo\UptimeMonitorBundle\Security\SecretRedactor;

use function preg_match;
use function str_contains;
use function strcspn;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;

/**
 * What a search result shows of a monitor target (since 1.32): the origin only, `scheme://host[:port]` for a URL or
 * `host[:port]` for a bare host. Userinfo, path, query string and fragment are dropped, so no credential written into
 * a URL (`user:pass@`, `?token=`, a bot token in the path) reaches a result.
 *
 * It fails closed: an empty string when the target is not a plain URL or host (a connection string such as
 * `Server=db;Password=x;`), when the host is not a hostname, IPv4 or `[IPv6]`, or when a `@` follows the first `/`, `?`
 * or `#` and a `:` stands before that `@` (a password written with a raw `/`, `?` or `#` would otherwise be read as
 * `host:port`: `https://admin:4242/rest@host/`). See {@see SecretRedactor::isAmbiguousAuthority()}.
 */
final class MonitorSearchUrl
{
    /** A hostname (letters, digits, `_`, `.`, `-`), an IPv4 address or an `[IPv6]` literal, with an optional port. */
    private const AUTHORITY = '/^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9_](?:[A-Za-z0-9_.\-]*[A-Za-z0-9_])?)(?::([0-9]{1,5}))?$/';

    /**
     * The displayable origin of a stored target; an empty string when it cannot be read safely.
     */
    public static function display(string $target): string
    {
        $scheme = '';
        $rest   = $target;
        if (preg_match('#^([a-z][a-z0-9+.\-]*)://(.*)$#is', $target, $m) === 1) {
            $scheme = strtolower($m[1]) . '://';
            $rest   = $m[2];
        }

        $authority = substr($rest, 0, strcspn($rest, '/?#'));
        $after     = substr($rest, strlen($authority));

        if (str_contains($authority, '@')) {
            $authority = substr($authority, (int) strrpos($authority, '@') + 1);
        } elseif (SecretRedactor::isAmbiguousAuthority($authority, $after)) {
            return '';
        }

        if (preg_match(self::AUTHORITY, $authority, $h) !== 1 || (isset($h[2]) && (int) $h[2] > 65535)) {
            return '';
        }

        return $scheme . strtolower($h[1]) . (isset($h[2]) ? ':' . $h[2] : '');
    }
}
