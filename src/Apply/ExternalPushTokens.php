<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

use LogicException;

use function array_key_exists;
use function array_keys;

/**
 * The push tokens handed to `nowo:uptime:apply-monitors` (since 1.18), already reduced to their SHA-256: the
 * plaintext never leaves {@see PushTokenFileReader}. A null hash means "keep the stored token". Dumps show the entry
 * keys only, and the object refuses to be serialized.
 */
final readonly class ExternalPushTokens
{
    /**
     * @param array<string, string|null> $hashes entry key => SHA-256 of its token (null = keep)
     */
    public function __construct(private array $hashes = [])
    {
    }

    /**
     * Whether the token input names this entry.
     */
    public function covers(string $key): bool
    {
        return array_key_exists($key, $this->hashes);
    }

    /**
     * The SHA-256 of the entry's new token (null: no token given, keep the stored one).
     */
    public function hashFor(string $key): ?string
    {
        return $this->hashes[$key] ?? null;
    }

    /**
     * @return list<string> the entry keys
     */
    public function keys(): array
    {
        return array_keys($this->hashes);
    }

    /**
     * @return array{keys: list<string>}
     */
    public function __debugInfo(): array
    {
        return ['keys' => $this->keys()];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Push tokens are not serializable.');
    }
}
