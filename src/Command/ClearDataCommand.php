<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Command;

use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Service\UptimeDataClearService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

#[AsCommand(
    name: 'nowo:uptime:clear-data',
    description: 'Delete all check results, aggregates, and incidents (keeps tenants and monitors)',
)]
final class ClearDataCommand extends Command
{
    public function __construct(
        private readonly UptimeDataClearService $dataClearService,
        private readonly DashboardSyncDispatcher $dashboardSyncDispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Limit to tenant slug')
            ->addOption('no-interaction', 'n', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $tenantSlug = $input->getOption('tenant');
        $tenantSlug = is_string($tenantSlug) && $tenantSlug !== '' ? $tenantSlug : null;

        $scope = $tenantSlug !== null ? sprintf('tenant "%s"', $tenantSlug) : 'all tenants';

        if (!$input->getOption('no-interaction')) {
            if (!$io->confirm(sprintf('Delete all uptime records for %s? Monitors and tenants are kept.', $scope), false)) {
                $io->note('Aborted.');

                return Command::SUCCESS;
            }
        }

        try {
            $counts = $this->dataClearService->clear($tenantSlug);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($tenantSlug !== null) {
            $this->dashboardSyncDispatcher->dispatchTenantRefresh($tenantSlug);
        } else {
            foreach ($this->dataClearService->listTenantSlugs() as $slug) {
                $this->dashboardSyncDispatcher->dispatchTenantRefresh($slug);
            }
        }

        $io->success(sprintf(
            'Cleared %d check(s), %d aggregate(s), %d incident(s). Reset %d monitor(s).',
            $counts['checks'],
            $counts['aggregates'],
            $counts['incidents'],
            $counts['monitors_reset'],
        ));

        return Command::SUCCESS;
    }
}
