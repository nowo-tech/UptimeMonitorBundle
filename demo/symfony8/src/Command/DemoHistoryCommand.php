<?php

declare(strict_types=1);

namespace App\Command;

use App\Controller\UptimeDemoProbeController;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\Entity\CheckResult;
use Nowo\UptimeMonitorBundle\Entity\Incident;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_fill;
use function array_filter;
use function array_values;
use function count;
use function crc32;
use function max;
use function mt_rand;
use function mt_srand;
use function sprintf;
use function str_contains;

/**
 * Demo only: adds a few extra local-probe monitors to the seeded [demo] group and backfills a deterministic
 * check history (latencies, one short outage, one resolved incident) so the dashboard, monitor page and
 * status page look like a monitor that has been running for a day (README screenshots / Playwright e2e).
 *
 * Every monitor targets the demo's own probe routes ({@see UptimeDemoProbeController}); nothing reaches
 * the public internet. Run `nowo:uptime:rollup` afterwards to rebuild the aggregate buckets.
 */
#[AsCommand(
    name: 'app:uptime:demo-history',
    description: 'Demo only: add extra local probe monitors and backfill a deterministic check history',
)]
final class DemoHistoryCommand extends Command
{
    private const PROBE_URL = 'http://php/demo/uptime/ok';

    /** Extra monitors (name => probe query) added next to demo_uptime_ok / demo_uptime_flaky. */
    private const EXTRA_MONITORS = [
        'Storefront'   => 'storefront',
        'Checkout API' => 'checkout',
        'Search API'   => 'search',
    ];

    /** Monitor that gets a short outage plus a resolved incident in its history. */
    private const OUTAGE_MONITOR = 'Checkout API';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly MonitorRepository $monitorRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Tenant slug', 'main')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Hours of history to backfill', '24')
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'Seconds between backfilled checks', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $slug  = (string) $input->getOption('tenant');
        $hours = max(1, (int) $input->getOption('hours'));
        $step  = max(60, (int) $input->getOption('step'));

        $tenant = $this->tenantRepository->findOneBySlug($slug);
        if (!$tenant instanceof Tenant) {
            $io->error(sprintf('Tenant "%s" not found. Run nowo:uptime:seed-demo first.', $slug));

            return Command::FAILURE;
        }

        $this->addExtraMonitors($tenant);

        $now   = new DateTimeImmutable();
        $since = $now->modify(sprintf('-%d hours', $hours));
        mt_srand(20261026);

        $monitors = $this->monitorRepository->findByTenantSlug($slug);
        $children = array_values(array_filter($monitors, static fn (Monitor $m): bool => $m->getType() !== MonitorType::Group));
        $groups   = array_values(array_filter($monitors, static fn (Monitor $m): bool => $m->getType() === MonitorType::Group));

        /** @var list<DateTimeImmutable> $slots */
        $slots = [];
        for ($at = $since; $at < $now->modify('-1 minute'); $at = $at->modify(sprintf('+%d seconds', $step))) {
            $slots[] = $at;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        $rows = 0;
        /** @var array<int, int> $downPerSlot */
        $downPerSlot = array_fill(0, count($slots), 0);
        foreach ($children as $monitor) {
            $this->clearHistory($monitor);
            $this->backdateCreation($monitor, $since);
            $rows += $this->backfillMonitor($monitor, $slots, $now, $downPerSlot);
        }
        foreach ($groups as $group) {
            $this->clearHistory($group);
            $this->backdateCreation($group, $since);
            $rows += $this->backfillGroup($group, $slots, count($children), $downPerSlot);
        }

        $connection->commit();

        $io->success(sprintf('Backfilled %d check result(s) over the last %d hour(s).', $rows, $hours));

        return Command::SUCCESS;
    }

    private function addExtraMonitors(Tenant $tenant): void
    {
        $group = $this->monitorRepository->findOneBy(['tenant' => $tenant, 'type' => MonitorType::Group]);

        foreach (self::EXTRA_MONITORS as $name => $service) {
            if ($this->monitorRepository->findOneBy(['tenant' => $tenant, 'name' => $name]) instanceof Monitor) {
                continue;
            }
            $url     = self::PROBE_URL . '?service=' . $service;
            $monitor = new Monitor($tenant, $name, MonitorType::Http, $url);
            $monitor
                ->setConfig(['url' => $url, 'expected_status_codes' => [200]])
                ->setIntervalSeconds(60)
                ->setNextCheckAt(new DateTimeImmutable());
            if ($group instanceof Monitor) {
                $monitor->setParent($group)->setProject(null);
            }
            $this->entityManager->persist($monitor);
        }

        $this->entityManager->flush();
    }

    private function clearHistory(Monitor $monitor): void
    {
        foreach ([CheckResult::class, Incident::class] as $class) {
            $this->entityManager->createQueryBuilder()
                ->delete($class, 'e')
                ->where('e.monitor = :monitor')
                ->setParameter('monitor', $monitor)
                ->getQuery()
                ->execute();
        }
    }

    private function backdateCreation(Monitor $monitor, DateTimeImmutable $since): void
    {
        $meta = $this->entityManager->getClassMetadata(Monitor::class);
        $this->entityManager->getConnection()->update(
            $meta->getTableName(),
            [$meta->getColumnName('createdAt') => $since],
            [$meta->getColumnName('id') => $monitor->getId()],
            [Types::DATETIME_IMMUTABLE, Types::INTEGER],
        );
    }

    /**
     * @param list<DateTimeImmutable> $slots
     * @param array<int, int> $downPerSlot incremented for every slot where this monitor is down
     */
    private function backfillMonitor(Monitor $monitor, array $slots, DateTimeImmutable $now, array &$downPerSlot): int
    {
        $name        = $monitor->getName();
        $isFlaky     = str_contains($name, 'flaky');
        $hasOutage   = $name === self::OUTAGE_MONITOR;
        $baseLatency = 18 + (crc32($name) % 30);
        $outageFrom  = $now->modify('-7 hours');
        $outageTo    = $now->modify('-6 hours -35 minutes');
        $slowUntil   = $outageTo->modify('+40 minutes');

        foreach ($slots as $i => $at) {
            $down = ($hasOutage && $at >= $outageFrom && $at < $outageTo) || ($isFlaky && mt_rand(1, 100) <= 4);
            $slow = $hasOutage && $at >= $outageTo && $at < $slowUntil;
            if ($down) {
                ++$downPerSlot[$i];
            }
            $this->insertResult(
                $monitor,
                $at,
                $down ? CheckStatus::Down : CheckStatus::Up,
                $down ? 0 : $baseLatency + mt_rand(0, 25) + ($slow ? 160 + mt_rand(0, 60) : 0),
                $down ? 500 : 200,
                $down ? 'Unexpected status code 500 (expected 200)' : null,
            );
        }

        if ($hasOutage) {
            $meta = $this->entityManager->getClassMetadata(Incident::class);
            $this->entityManager->getConnection()->insert($meta->getTableName(), [
                $meta->getSingleAssociationJoinColumnName('monitor') => $monitor->getId(),
                $meta->getColumnName('triggerStatus')                => CheckStatus::Down->value,
                $meta->getColumnName('message')                      => 'Unexpected status code 500 (expected 200)',
                $meta->getColumnName('startedAt')                    => $outageFrom,
                $meta->getColumnName('endedAt')                      => $outageTo,
            ], [Types::INTEGER, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE]);
        }

        return count($slots);
    }

    /**
     * Group heartbeat mirrors the children: down while any child is down ("n/m child monitor(s) up").
     *
     * @param list<DateTimeImmutable> $slots
     * @param array<int, int> $downPerSlot
     */
    private function backfillGroup(Monitor $group, array $slots, int $children, array $downPerSlot): int
    {
        foreach ($slots as $i => $at) {
            $up = $children - $downPerSlot[$i];
            $this->insertResult(
                $group,
                $at,
                $up === $children ? CheckStatus::Up : CheckStatus::Down,
                0,
                null,
                sprintf('%d/%d child monitor(s) up', $up, $children),
            );
        }

        return count($slots);
    }

    private function insertResult(
        Monitor $monitor,
        DateTimeImmutable $at,
        CheckStatus $status,
        int $latencyMs,
        ?int $statusCode,
        ?string $message,
    ): void {
        $meta = $this->entityManager->getClassMetadata(CheckResult::class);
        $this->entityManager->getConnection()->insert($meta->getTableName(), [
            $meta->getSingleAssociationJoinColumnName('monitor') => $monitor->getId(),
            $meta->getColumnName('status')                       => $status->value,
            $meta->getColumnName('latencyMs')                    => $latencyMs,
            $meta->getColumnName('statusCode')                   => $statusCode,
            $meta->getColumnName('message')                      => $message,
            $meta->getColumnName('checkedAt')                    => $at,
        ], [Types::INTEGER, Types::STRING, Types::INTEGER, Types::INTEGER, Types::STRING, Types::DATETIME_IMMUTABLE]);
    }
}
