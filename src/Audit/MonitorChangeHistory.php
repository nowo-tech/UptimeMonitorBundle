<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\UptimeMonitorBundle\Doctrine\KeysetBatchDelete;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\MonitorChange;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function max;
use function sprintf;
use function strtr;

/**
 * The built-in history of monitor changes (since 1.33, `audit.history.enabled`): what the monitor page shows, the
 * retention purge (`audit.history.retention_days`) and the GDPR erasure of an actor.
 *
 * Reads and deletes through the bundle's private manager only. Stateless (FrankenPHP worker mode).
 */
final readonly class MonitorChangeHistory
{
    /**
     * @param EntityManagerInterface $entityManager The bundle's private manager
     * @param MonitorChangeRecorder $recorder Tells whether the history is enabled
     * @param int $retentionDays `audit.history.retention_days` (0 = keep forever)
     * @param int $displayLimit `audit.history.display_limit`: rows shown on the monitor page
     * @param int $batchSize Rows deleted per keyset batch by the purge (`retention.purge_batch_size`)
     * @param ClockInterface $clock The purge cut-off
     * @param UptimeTransactionRunner|null $transactionRunner The bundle's runner (null = one on $entityManager)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MonitorChangeRecorder $recorder,
        private int $retentionDays = 365,
        private int $displayLimit = 50,
        private int $batchSize = KeysetBatchDelete::DEFAULT_BATCH_SIZE,
        private ClockInterface $clock = new NativeClock(),
        private ?UptimeTransactionRunner $transactionRunner = null,
    ) {
    }

    /**
     * Whether the history is written and shown.
     */
    public function isEnabled(): bool
    {
        return $this->recorder->isHistoryEnabled();
    }

    /**
     * The latest changes of a monitor, newest first (at most `audit.history.display_limit`); none when disabled.
     *
     * @return list<MonitorChange>
     */
    public function forMonitor(Monitor $monitor): array
    {
        if (!$this->isEnabled() || $monitor->getId() === null) {
            return [];
        }

        /** @var list<MonitorChange> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(MonitorChange::class, 'c')
            ->where('c.tenant = :tenant')
            ->andWhere('c.monitorId = :monitor')
            ->orderBy('c.occurredAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setParameter('tenant', $monitor->getTenant())
            ->setParameter('monitor', $monitor->getId())
            ->setMaxResults(max(1, $this->displayLimit))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Deletes the rows older than the retention, in short keyset batches; 0 when the retention is 0 (keep forever).
     * Runs whether or not the history is enabled, so turning it off still lets the rows age out.
     *
     * @return int Rows deleted
     */
    public function purge(): int
    {
        if ($this->retentionDays <= 0) {
            return 0;
        }

        $cutoff = $this->clock->now()->modify(sprintf('-%d days', $this->retentionDays));

        return KeysetBatchDelete::run(
            $this->entityManager,
            MonitorChange::class,
            static function (QueryBuilder $qb, string $alias) use ($cutoff): void {
                $qb->andWhere($alias . '.occurredAt < :cutoff')->setParameter('cutoff', $cutoff);
            },
            $this->batchSize,
        );
    }

    /**
     * GDPR erasure: replaces the actor of every row that names this identifier by {@see MonitorChange::ANONYMISED_ACTOR}
     * (the changes themselves stay): the identifier itself, and the impersonation forms `<id> (as …)` and `… (as <id>)`
     * of `switch_user` (the whole actor of such a row is replaced, the other user included). One statement of the
     * bundle's runner; `%`, `_` and `!` in the identifier match literally.
     *
     * @return int Rows anonymised
     */
    public function anonymiseActor(string $actor): int
    {
        $entityManager = $this->entityManager;
        $like          = strtr($actor, ['!' => '!!', '%' => '!%', '_' => '!_']);

        return (int) ($this->transactionRunner ?? new UptimeTransactionRunner($entityManager))->run(static fn (): mixed => $entityManager->createQueryBuilder()
            ->update(MonitorChange::class, 'c')
            ->set('c.actor', ':anonymised')
            ->where('c.actor = :actor')
            ->orWhere("c.actor LIKE :impersonator ESCAPE '!'")
            ->orWhere("c.actor LIKE :impersonated ESCAPE '!'")
            ->setParameter('anonymised', MonitorChange::ANONYMISED_ACTOR)
            ->setParameter('actor', $actor)
            ->setParameter('impersonator', $like . ' (as %')
            ->setParameter('impersonated', '% (as ' . $like . ')')
            ->getQuery()
            ->execute());
    }
}
