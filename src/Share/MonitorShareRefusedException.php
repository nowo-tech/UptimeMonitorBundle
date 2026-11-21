<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Share;

use DomainException;

/**
 * A share that {@see MonitorSharesInterface::create()} refuses (since 1.35); nothing was written. `reason` is one of the
 * constants; the message is constant and safe to show (no identifier, no SQL).
 */
final class MonitorShareRefusedException extends DomainException
{
    /** The monitor does not exist in that tenant. */
    public const MONITOR_NOT_FOUND = 'monitor_not_found';

    /** The host policy refused the share ({@see MonitorSharePolicyInterface}). */
    public const DENIED = 'denied';

    /** The user identifier is empty, longer than 128 characters or holds control characters. */
    public const INVALID_USER = 'invalid_user';

    /**
     * Since 1.35: the database compares the identifier equal to one already shared (case, accents or trailing spaces on
     * a `user_identifier` column without the binary NO PAD collation). Fix the column (UPGRADING) or the identifiers.
     */
    public const IDENTIFIER_COLLISION = 'identifier_collision';

    private const MESSAGES = [
        self::MONITOR_NOT_FOUND    => 'The monitor was not found.',
        self::DENIED               => 'The monitor may not be shared with this user.',
        self::INVALID_USER         => 'The user identifier is not valid.',
        self::IDENTIFIER_COLLISION => 'The user identifier collides with another one in the database.',
    ];

    /**
     * @param string $reason One of the constants
     */
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct(self::MESSAGES[$reason] ?? 'The share was refused.');
    }
}
