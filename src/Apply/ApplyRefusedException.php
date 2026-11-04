<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Apply;

use RuntimeException;

use function array_map;
use function array_unique;
use function array_values;
use function implode;
use function min;

/**
 * `nowo:uptime:apply-monitors` refused its input (since 1.18): nothing was written. The message holds only the error
 * codes, and there is never a previous exception, so no token, hash or input value can travel with it (Beacon ships
 * exceptions to its issues).
 */
final class ApplyRefusedException extends RuntimeException
{
    /**
     * @param list<ApplyError> $errors At least one error
     */
    public function __construct(private readonly array $errors)
    {
        $codes = array_values(array_unique(array_map(static fn (ApplyError $error): string => $error->code, $this->errors)));
        parent::__construct('apply_refused: ' . implode(', ', $codes));
    }

    /**
     * @return list<ApplyError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * The command exit code: the lowest class among the errors (usage 2, file 3, token 4, tenant 5, conflict 6).
     */
    public function getExitCode(): int
    {
        return min(ApplyError::EXIT_CONFLICT, ...array_map(static fn (ApplyError $error): int => $error->exitCode, $this->errors));
    }
}
