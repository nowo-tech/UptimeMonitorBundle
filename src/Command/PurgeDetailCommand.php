<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Command;

use Nowo\UptimeMonitorBundle\Service\DetailRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'nowo:uptime:purge-detail',
    description: 'Purge check detail rows older than the configured retention period',
)]
final class PurgeDetailCommand extends Command
{
    public function __construct(
        private readonly DetailRetentionService $retentionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $purged = $this->retentionService->purgeExpiredDetail();

        $io->success(sprintf('Purged %d detail record(s).', $purged));

        return Command::SUCCESS;
    }
}
