<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Push;

use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function abs;
use function base64_encode;
use function bin2hex;
use function ctype_digit;
use function hash;
use function hash_equals;
use function hash_hmac;
use function random_bytes;
use function rtrim;
use function str_repeat;
use function strtolower;
use function strtr;

/**
 * Credentials of a push monitor (since 1.14): the opaque key of its URL, the bearer token and the optional signing
 * secret.
 *
 * The token is shown once and only its SHA-256 is stored; the signing secret is never stored either — it is derived
 * from `kernel.secret`, the key and the token hash, so rotating the token rotates it too, and a database dump alone
 * cannot sign a push.
 */
final readonly class PushCredentials
{
    public const TOKEN_PREFIX = 'upm_';

    /** Compared against when the key matches no monitor, so an unknown key costs the same as a wrong token. */
    private const NO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param string $secret `kernel.secret`, the root of the signing secrets
     */
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    /**
     * Gives a push monitor its URL key when it has none (128 random bits, hex).
     */
    public static function ensureKey(Monitor $monitor): void
    {
        if ($monitor->getPushKey() === null) {
            $monitor->setPushKey(bin2hex(random_bytes(16)));
        }
    }

    /**
     * Issues a new token (the previous one stops working at once) and returns it: it is never readable again.
     */
    public function issueToken(Monitor $monitor): string
    {
        self::ensureKey($monitor);
        $token = self::TOKEN_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $monitor->setPushTokenHash(hash('sha256', $token));

        return $token;
    }

    /**
     * Revokes the token: every push is refused until a new one is issued.
     */
    public function revoke(Monitor $monitor): void
    {
        $monitor->setPushTokenHash(null);
    }

    /**
     * Whether the bearer token belongs to the monitor; constant time, and a missing monitor or token costs the same.
     */
    public function verifyToken(?Monitor $monitor, string $token): bool
    {
        $stored = $monitor?->getPushTokenHash();
        $match  = hash_equals($stored ?? self::NO_HASH, hash('sha256', $token));

        return $match && $stored !== null && $token !== '';
    }

    /**
     * The HMAC-SHA256 secret a signing agent uses (null while the monitor has no token).
     */
    public function signingSecret(Monitor $monitor): ?string
    {
        $hash = $monitor->getPushTokenHash();
        if ($hash === null || $monitor->getPushKey() === null) {
            return null;
        }

        return hash_hmac('sha256', 'nowo_uptime_push_signature|' . $monitor->getPushKey() . '|' . $hash, $this->secret);
    }

    /**
     * Whether `signature` is `hex(hmac_sha256(secret, timestamp . "." . body))` and the timestamp is within the window.
     */
    public function verifySignature(Monitor $monitor, string $body, string $timestamp, string $signature, int $now, int $windowSeconds): bool
    {
        $secret = $this->signingSecret($monitor);
        $fresh  = ctype_digit($timestamp) && abs($now - (int) $timestamp) <= $windowSeconds;
        $match  = hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret ?? str_repeat('0', 64)), strtolower($signature));

        return $match && $fresh && $secret !== null;
    }
}
