<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Security;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

use function dns_get_record;
use function explode;
use function filter_var;
use function gethostbynamel;
use function is_array;
use function is_string;
use function rtrim;
use function str_ends_with;
use function strtolower;
use function trim;

use const DNS_AAAA;
use const FILTER_VALIDATE_IP;
use const PHP_URL_HOST;

/**
 * Blocks monitor URLs / hosts that target private/local networks (SSRF mitigation).
 *
 * Every resolved address (A + AAAA) is checked against {@see self::BLOCKED_SUBNETS}; runners that open sockets
 * themselves (TCP/SSL/ping) connect to the address returned by {@see self::resolveAddresses()} so the target
 * cannot be re-resolved to a private address between the check and the connection (DNS rebinding).
 */
final class MonitorUrlSsrfGuard
{
    /**
     * IpUtils::PRIVATE_SUBNETS (loopback, RFC1918, link-local, 0.0.0.0/8, 100.64/10, fc00::/7, IPv4-mapped, ::, …)
     * plus multicast/broadcast and IETF protocol assignments.
     */
    public const BLOCKED_SUBNETS = [
        ...IpUtils::PRIVATE_SUBNETS,
        '192.0.0.0/24',       // IETF protocol assignments (RFC 6890)
        '224.0.0.0/4',        // Multicast
        '255.255.255.255/32', // Broadcast
        'ff00::/8',           // IPv6 multicast
    ];

    private const BLOCKED_HOSTNAMES = ['localhost', 'metadata.google.internal'];

    /** @var Closure(string): (false|list<string>) */
    private readonly Closure $ipv4Lookup;

    /** @var Closure(string, int): (array<int, array<string, mixed>>|false) */
    private readonly Closure $recordLookup;

    /**
     * The lookups are the guard's DNS seam (tests resolve names without network access).
     *
     * @param (Closure(string): (false|list<string>))|null $ipv4Lookup A lookup (defaults to gethostbynamel)
     * @param (Closure(string, int): (array<int, array<string, mixed>>|false))|null $recordLookup Record lookup (defaults to dns_get_record)
     */
    public function __construct(?Closure $ipv4Lookup = null, ?Closure $recordLookup = null)
    {
        $this->ipv4Lookup   = $ipv4Lookup ?? gethostbynamel(...);
        $this->recordLookup = $recordLookup ?? static fn (string $host, int $type): array|false => @dns_get_record($host, $type);
    }

    public function isBlocked(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return true;
        }

        return $this->isBlockedHost($host);
    }

    /**
     * Whether a bare host/IP (e.g. ping target) is or resolves to a private/local address.
     *
     * Unresolvable hostnames are not blocked here (the check itself will fail to connect).
     */
    public function isBlockedHost(string $host): bool
    {
        return $this->isBlockedResolution($host, $this->resolveAddresses($host));
    }

    /**
     * Whether a host or any of its already-resolved addresses is private/local (no second DNS lookup).
     *
     * @param list<string> $addresses
     */
    public function isBlockedResolution(string $host, array $addresses): bool
    {
        if ($this->isBlockedHostname($host)) {
            return true;
        }

        foreach ($addresses as $ip) {
            if ($this->isBlockedIp($ip)) {
                return true;
            }
        }

        return false;
    }

    public function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return IpUtils::checkIp($ip, self::BLOCKED_SUBNETS);
    }

    /**
     * Resolves a host (or IP literal, optionally bracketed) to all its A/AAAA addresses.
     *
     * @return list<string>
     */
    public function resolveAddresses(string $host): array
    {
        $host = explode('%', trim($host, '[]'), 2)[0];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = ($this->ipv4Lookup)($host);
        $addresses = is_array($addresses) ? $addresses : [];

        $aaaa = ($this->recordLookup)($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return $addresses;
    }

    private function isBlockedHostname(string $host): bool
    {
        $hostLower = rtrim(strtolower(trim($host, '[]')), '.');

        foreach (self::BLOCKED_HOSTNAMES as $blocked) {
            if ($hostLower === $blocked || str_ends_with($hostLower, '.' . $blocked)) {
                return true;
            }
        }

        return false;
    }
}
