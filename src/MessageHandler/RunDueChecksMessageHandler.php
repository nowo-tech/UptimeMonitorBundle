<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\MessageHandler;

use Nowo\UptimeMonitorBundle\Message\RunDueChecksMessage;
use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunDueChecksMessageHandler
{
    public function __construct(
        private DueChecksRunner $dueChecksRunner,
    ) {
    }

    public function __invoke(RunDueChecksMessage $message): void
    {
        $this->dueChecksRunner->runDueChecks();
    }
}
