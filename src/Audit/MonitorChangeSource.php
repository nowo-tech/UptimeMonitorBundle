<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use InvalidArgumentException;

use function preg_match;
use function sprintf;

/**
 * The write path recorded with each monitor change (since 1.33).
 */
final class MonitorChangeSource
{
    /** The admin UI: new / edit forms, delete, pause / resume, push token issue / revoke. */
    public const UI = 'ui';

    /** The backup import (settings page). */
    public const IMPORT = 'import';

    /** `nowo:uptime:apply-monitors` (a monitors file). */
    public const APPLY = 'apply';

    /** `nowo:uptime:seed-demo`. */
    public const DEMO = 'demo';

    /** Anything else: host code and console commands that write monitors through the bundle services. */
    public const APP = 'app';

    /** What a write path name may be (it is stored in a 16-character column). */
    public const PATTERN = '/^[a-z_]{1,16}$/D';

    /**
     * Refuses a write path name that the history could not store.
     *
     * @throws InvalidArgumentException When the name does not match {@see self::PATTERN}
     */
    public static function assertValid(string $source): void
    {
        if (preg_match(self::PATTERN, $source) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid monitor change source "%s": use 1 to 16 lowercase letters or underscores.', $source));
        }
    }
}
