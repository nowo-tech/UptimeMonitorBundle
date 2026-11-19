<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Search;

use Nowo\UptimeMonitorBundle\Security\SecretRedactor;

use function preg_match;
use function strcspn;
use function strrpos;
use function strtolower;
use function substr;

/**
 * What a search result shows of a monitor target (since 1.32): the origin only, `scheme://host[:port]` for a URL or
 * `host[:port]` for a bare host. Userinfo, path, query string and fragment are dropped, so no credential written into
 * a URL (`user:pass@`, `?token=`, a bot token in the path) reaches a result.
 *
 * It fails closed. An ambiguous URL (an `@` after the first `/`, `?` or `#` with a `:` before it or an `@` in the
 * authority: `https://admin:4242/rest@host/`, `https://host:8443/n?to=ops@example.com`) shows `scheme://***` and no
 * host, because a password written with a raw `/`, `?` or `#` cannot be told from a path
 * ({@see SecretRedactor::isAmbiguousUrl()}). An empty string when the target is not a plain URL or host (a connection
 * string such as `Server=db;Password=x;`) or the host is not a hostname, IPv4 or `[IPv6]`.
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
        if (SecretRedactor::isAmbiguousUrl($target)) {
            return strtolower(SecretRedactor::opaque($target));
        }

        $scheme = '';
        $rest   = $target;
        if (preg_match('#^([a-z][a-z0-9+.\-]*)://(.*)$#is', $target, $m) === 1) {
            $scheme = strtolower($m[1]) . '://';
            $rest   = $m[2];
        }

        $authority = substr($rest, 0, strcspn($rest, '/?#'));
        $at        = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }

        if (preg_match(self::AUTHORITY, $authority, $h) !== 1 || (isset($h[2]) && (int) $h[2] > 65535)) {
            return '';
        }

        return $scheme . strtolower($h[1]) . (isset($h[2]) ? ':' . $h[2] : '');
    }
}
