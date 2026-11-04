<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

use RuntimeException;
use Throwable;

/**
 * `nowo:uptime:apply-monitors` failed while writing (since 1.18), and the transaction was rolled back. It replaces
 * the database or driver exception on purpose: the original message may quote SQL parameters such as the token hash,
 * so only its class is kept, and there is no previous exception.
 */
final class ApplyFailedException extends RuntimeException
{
    /**
     * @param string $causeClass Class of the replaced exception
     */
    private function __construct(private readonly string $causeClass)
    {
        parent::__construct('apply_failed: ' . $causeClass);
    }

    /**
     * @param Throwable $cause The failure; only its class survives
     */
    public static function from(Throwable $cause): self
    {
        return new self($cause::class);
    }

    /**
     * Class of the replaced exception (the only part of it that is logged).
     */
    public function getCauseClass(): string
    {
        return $this->causeClass;
    }
}
