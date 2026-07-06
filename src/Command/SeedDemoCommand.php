<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Command;

use Nowo\UptimeMonitorBundle\Service\DashboardSyncDispatcher;
use Nowo\UptimeMonitorBundle\Service\DemoSeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'nowo:uptime:seed-demo',
    description: 'Seed demo tenant with one project group and local HTTP probe monitors',
)]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private readonly DemoSeedService $demoSeedService,
        private readonly DashboardSyncDispatcher $dashboardSyncDispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Tenant slug', 'main')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Tenant display name', 'Main')
            ->addOption('fresh', 'f', InputOption::VALUE_NONE, 'Remove all monitors for the tenant before seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $slug  = (string) $input->getOption('tenant');
        $name  = (string) $input->getOption('name');
        $fresh = (bool) $input->getOption('fresh');

        $result = $fresh
            ? $this->demoSeedService->freshSeed($slug, $name)
            : $this->demoSeedService->seed($slug, $name);

        if ($fresh) {
            $io->success(sprintf(
                'Tenant "%s" reset. Removed %d monitor(s), created %d (1 group + demo_uptime_ok + demo_uptime_flaky).',
                $result['tenant']->getSlug(),
                $result['monitors_removed'],
                $result['monitors_created'],
            ));
        } else {
            $io->success(sprintf(
                'Tenant "%s" ready. Created %d new monitor(s). Use --fresh to replace the full demo tree.',
                $result['tenant']->getSlug(),
                $result['monitors_created'],
            ));
        }

        $this->dashboardSyncDispatcher->dispatchTenantRefresh($slug);
        $io->note('Dashboard clients notified. Reload /uptime if the monitor list still looks stale.');

        return Command::SUCCESS;
    }
}
