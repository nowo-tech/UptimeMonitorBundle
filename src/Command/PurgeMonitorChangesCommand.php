<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Command;

use Nowo\UptimeMonitorBundle\Audit\MonitorChangeHistory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

/**
 * Console command `nowo:uptime:purge-monitor-changes` (since 1.33): deletes the monitor change history older than
 * `audit.history.retention_days`, or, with `--anonymise-actor=<id>`, replaces the actor of every row that names that
 * identifier (GDPR erasure) instead, the `switch_user` forms `<id> (as …)` and `… (as <id>)` included.
 */
#[AsCommand(
    name: 'nowo:uptime:purge-monitor-changes',
    description: 'Purge monitor change history older than the configured retention, or anonymise one actor',
)]
final class PurgeMonitorChangesCommand extends Command
{
    /**
     * Creates the command.
     *
     * @param MonitorChangeHistory $history The monitor change history
     */
    public function __construct(
        private readonly MonitorChangeHistory $history,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('anonymise-actor', null, InputOption::VALUE_REQUIRED, 'Anonymise every history row whose actor is this identifier or names it under switch_user (GDPR erasure) instead of purging');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $actor = $input->getOption('anonymise-actor');
        if (is_string($actor)) {
            if ($actor === '') {
                $io->error('--anonymise-actor needs an actor identifier.');

                return Command::INVALID;
            }
            $io->success(sprintf('Anonymised %d monitor change(s).', $this->history->anonymiseActor($actor)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Purged %d monitor change(s).', $this->history->purge()));

        return Command::SUCCESS;
    }
}
