<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\MessageHandler;

use Nowo\UptimeMonitorBundle\Message\RunDueChecksMessage;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Runs the due-check scan for {@see RunDueChecksMessage} (Scheduler tick or a host-routed async message).
 *
 * Idempotent on redelivery: only monitors whose next_check_at is due are executed and each check moves it forward,
 * so a retried message re-runs at most the monitors that were not checked yet. Failures are logged (message class +
 * exception class, no payload) and rethrown so the host Messenger retry strategy / failure transport applies — see
 * docs/SCHEDULING.md.
 */
#[AsMessageHandler]
final readonly class RunDueChecksMessageHandler
{
    /**
     * Creates the handler around the due-check runner.
     *
     * @param LoggerInterface|null $logger Logger for the scan result and failures
     */
    public function __construct(
        private DueChecksRunner $dueChecksRunner,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Runs every due monitor check and logs how many ran.
     *
     * @throws Throwable Any scan failure, rethrown for the Messenger retry strategy
     */
    public function __invoke(RunDueChecksMessage $message): void
    {
        $context = ['bundle' => 'NowoUptimeMonitorBundle', 'message' => RunDueChecksMessage::class];

        try {
            $executed = $this->dueChecksRunner->runDueChecks();
        } catch (Throwable $e) {
            $this->logger?->error('Uptime due-check scan failed: {error}', $context + ['error' => $e::class]);

            throw $e;
        }

        $this->logger?->debug('Uptime due-check scan executed {executed} check(s)', $context + ['executed' => $executed]);
    }
}
