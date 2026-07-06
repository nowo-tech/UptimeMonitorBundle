<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Command;

use Nowo\UptimeMonitorBundle\Service\DueChecksRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'nowo:uptime:run-due-checks',
    description: 'Run all uptime monitors that are due for a check',
)]
final class RunDueChecksCommand extends Command
{
    public function __construct(
        private readonly DueChecksRunner $dueChecksRunner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $count = $this->dueChecksRunner->runDueChecks();

        $io->success(sprintf('Executed %d monitor check(s).', $count));

        return Command::SUCCESS;
    }
}
