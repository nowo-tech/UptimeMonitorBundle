<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Share;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Query\MonitorListCriteria;
use Nowo\UptimeMonitorBundle\Share\Entity\MonitorShare;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function array_keys;
use function array_map;
use function is_array;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function preg_match;
use function sort;
use function strval;

use const SORT_STRING;

/**
 * Default {@see MonitorSharesInterface} (since 1.35) on the bundle's own entity manager.
 *
 * - Every write is one unit of the bundle's runner (its own transaction, or a savepoint of the caller's); the bundle
 *   never flushes, clears, resets or closes an application manager (REQ-RUNTIME-003 rule 2).
 * - `create()` is idempotent: the unique key (monitor, user) decides the race of two concurrent submits; the loser's
 *   unique violation is caught and the winner's row returned (rule 4: unique constraint plus catch). A lock conflict
 *   surfaces as the runner's {@see TransientStorageFailure} (409 + Retry-After + CONFLICT_001, or CONFLICT_002 when part
 *   of the request was committed: rule 6); outside a caller's transaction it is replayed a few times first.
 * - User identifiers and slugs compare exactly: binary NO PAD column on MySQL / MariaDB, and a PHP `===` re-check of
 *   every row read.
 * - Reads return {@see MonitorShareView}s (scalar queries); a written share is detached at once, so nothing is kept
 *   managed across requests (FrankenPHP worker mode, kernel reset off). Stateless.
 */
final readonly class MonitorShares implements MonitorSharesInterface
{
    /** Longest user identifier (the column length). */
    public const MAX_USER_IDENTIFIER_LENGTH = 128;

    /**
     * @param EntityManagerInterface $entityManager The bundle's private manager
     * @param MonitorSharePolicyInterface $policy Who a monitor may be shared with (deny by default)
     * @param ClockInterface $clock Time of a new share
     * @param UptimeTransactionRunner|null $transactionRunner The bundle's runner (null = one on $entityManager)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MonitorSharePolicyInterface $policy = new DenyAllMonitorSharePolicy(),
        private ClockInterface $clock = new NativeClock(),
        private ?UptimeTransactionRunner $transactionRunner = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function create(string $tenantSlug, int $monitorId, string $userIdentifier): MonitorShareView
    {
        if (!self::isValidUser($userIdentifier)) {
            throw new MonitorShareRefusedException(MonitorShareRefusedException::INVALID_USER);
        }
        if (!$this->monitorInTenant($tenantSlug, $monitorId)) {
            throw new MonitorShareRefusedException(MonitorShareRefusedException::MONITOR_NOT_FOUND);
        }
        if (!$this->policy->canShareWith($tenantSlug, $monitorId, $userIdentifier)) {
            throw new MonitorShareRefusedException(MonitorShareRefusedException::DENIED);
        }

        $existing = $this->find($tenantSlug, $monitorId, $userIdentifier);
        if ($existing instanceof MonitorShareView) {
            return $existing;
        }

        try {
            return $this->insert($tenantSlug, $monitorId, $userIdentifier);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request wrote the same pair first: its row is the share.
            [$winner, $lookAlike] = $this->findWinner($tenantSlug, $monitorId, $userIdentifier);
            if ($winner instanceof MonitorShareView) {
                return $winner;
            }
            if ($lookAlike) {
                // The unique key compares this identifier equal to another one (case, accents or trailing spaces on a
                // column without the binary collation): a retry would never succeed (audit XZ-4).
                throw new MonitorShareRefusedException(MonitorShareRefusedException::IDENTIFIER_COLLISION);
            }

            // The winner's row is gone again (deleted in between): nothing of this call is committed, retry is safe.
            throw new TransientStorageFailure(1, 'A concurrent request changed the same share. Retry later.');
        } catch (ForeignKeyConstraintViolationException) {
            // The monitor was deleted since it was checked: nothing was written.
            throw new MonitorShareRefusedException(MonitorShareRefusedException::MONITOR_NOT_FOUND);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $tenantSlug, int $monitorId, string $userIdentifier): bool
    {
        if (!self::isValidUser($userIdentifier)) {
            return false;
        }
        $share = $this->find($tenantSlug, $monitorId, $userIdentifier);

        return $share instanceof MonitorShareView && $this->deleteIds([$share->id]) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function usersOf(string $tenantSlug, int $monitorId): array
    {
        if (!MonitorListCriteria::isValidSlug($tenantSlug) || $monitorId < 1) {
            return [];
        }

        $users = [];
        foreach ($this->rows($this->select()->andWhere('m.id = :monitorId')->andWhere('t.slug = :slug')->setParameter('monitorId', $monitorId)->setParameter('slug', $tenantSlug)) as $view) {
            if ($view->tenantSlug === $tenantSlug && $view->monitorId === $monitorId) {
                $users[$view->userIdentifier] = true;
            }
        }
        $users = array_map(strval(...), array_keys($users));
        sort($users, SORT_STRING);

        return $users;
    }

    /**
     * {@inheritDoc}
     */
    public function sharedWith(string $userIdentifier): array
    {
        if (!self::isValidUser($userIdentifier)) {
            return [];
        }

        $shares = [];
        foreach ($this->rows($this->select()->andWhere('s.userIdentifier = :user')->setParameter('user', $userIdentifier)->orderBy('s.id', 'ASC')) as $view) {
            if ($view->userIdentifier === $userIdentifier) {
                $shares[] = $view;
            }
        }

        return $shares;
    }

    /**
     * {@inheritDoc}
     */
    public function isSharedWith(string $tenantSlug, int $monitorId, string $userIdentifier): bool
    {
        return self::isValidUser($userIdentifier) && $this->find($tenantSlug, $monitorId, $userIdentifier) instanceof MonitorShareView;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteAllForUser(string $userIdentifier): int
    {
        $ids = [];
        foreach ($this->sharedWith($userIdentifier) as $share) {
            $ids[] = $share->id;
        }

        return $ids === [] ? 0 : $this->deleteIds($ids);
    }

    /**
     * Whether the value is a user identifier the shares accept: 1 to 128 characters of valid UTF-8, no control
     * characters (it is never trimmed or case-folded).
     */
    public static function isValidUser(string $userIdentifier): bool
    {
        return $userIdentifier !== ''
            && preg_match('//u', $userIdentifier) === 1
            && mb_strlen($userIdentifier, 'UTF-8') <= self::MAX_USER_IDENTIFIER_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $userIdentifier) !== 1;
    }

    /**
     * Writes the share in one unit of the runner and returns it (detached from the bundle's manager).
     */
    private function insert(string $tenantSlug, int $monitorId, string $userIdentifier): MonitorShareView
    {
        $manager = $this->entityManager;
        $now     = DateTimeImmutable::createFromInterface($this->clock->now());
        $share   = $this->runner()->runRetrying(static function () use ($manager, $monitorId, $userIdentifier, $now): MonitorShare {
            // A reference by id: no monitor is loaded, none from an earlier attempt is reused.
            /** @var Monitor $monitor */
            $monitor = $manager->getReference(Monitor::class, $monitorId);
            $share   = new MonitorShare($monitor, $userIdentifier, $now);
            $manager->persist($share);

            return $share;
        });
        $view = new MonitorShareView((int) $share->getId(), $monitorId, $tenantSlug, $userIdentifier, $now, true);
        // Nothing of this unit stays managed: the share, and the monitor reference when this call created it (a monitor
        // the manager already held for other code is left to it).
        $manager->detach($share);
        $reference = $share->getMonitor();
        if ($manager->getUnitOfWork()->isUninitializedObject($reference)) {
            $manager->detach($reference);
        }

        return $view;
    }

    /**
     * Removes the shares with these ids in one statement (one unit of the runner).
     *
     * @param list<int> $ids
     */
    private function deleteIds(array $ids): int
    {
        $manager = $this->entityManager;

        return $this->runner()->runRetrying(static fn (): int => (int) $manager->createQueryBuilder()
            ->delete(MonitorShare::class, 's')
            ->andWhere('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());
    }

    /**
     * After a lost insert race: the winner's share row of that pair (the monitor's tenant was checked before), and
     * whether the database matched only look-alike identifiers. The read runs as one unit of the runner with a shared
     * lock on the share rows (audit XZ-5): it sees the committed row even inside a caller's REPEATABLE READ transaction,
     * and a lock wait or deadlock surfaces as a TransientStorageFailure.
     *
     * @return array{0: MonitorShareView|null, 1: bool}
     */
    private function findWinner(string $tenantSlug, int $monitorId, string $userIdentifier): array
    {
        $manager = $this->entityManager;
        /** @var list<mixed> $found */
        $found = $this->runner()->runRetrying(static fn (): array => $manager->createQueryBuilder()
            ->select('s.id AS id', 'IDENTITY(s.monitor) AS monitorId', 's.userIdentifier AS userIdentifier', 's.createdAt AS createdAt')
            ->from(MonitorShare::class, 's')
            ->andWhere('s.monitor = :monitorId')
            ->andWhere('s.userIdentifier = :user')
            ->setParameter('monitorId', $monitorId)
            ->setParameter('user', $userIdentifier)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_READ)
            ->getArrayResult());

        $rows = [];
        foreach ($found as $row) {
            $rows[] = (is_array($row) ? $row : []) + ['tenantSlug' => $tenantSlug];
        }
        $views = $this->hydrate($rows);
        foreach ($views as $view) {
            if ($view->monitorId === $monitorId && $view->userIdentifier === $userIdentifier) {
                return [$view, false];
            }
        }

        return [null, $views !== []];
    }

    /**
     * The share of that pair in that tenant (exact comparison), else null.
     */
    private function find(string $tenantSlug, int $monitorId, string $userIdentifier): ?MonitorShareView
    {
        if (!MonitorListCriteria::isValidSlug($tenantSlug) || $monitorId < 1) {
            return null;
        }

        $qb = $this->select()
            ->andWhere('m.id = :monitorId')
            ->andWhere('t.slug = :slug')
            ->andWhere('s.userIdentifier = :user')
            ->setParameter('monitorId', $monitorId)
            ->setParameter('slug', $tenantSlug)
            ->setParameter('user', $userIdentifier);
        foreach ($this->hydrate($qb->getQuery()->getArrayResult()) as $view) {
            if ($view->tenantSlug === $tenantSlug && $view->monitorId === $monitorId && $view->userIdentifier === $userIdentifier) {
                return $view;
            }
        }

        return null;
    }

    /**
     * Whether the monitor exists in that tenant (exact slug).
     */
    private function monitorInTenant(string $tenantSlug, int $monitorId): bool
    {
        if (!MonitorListCriteria::isValidSlug($tenantSlug) || $monitorId < 1) {
            return false;
        }

        /** @var list<array{slug: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.slug AS slug')
            ->from(Monitor::class, 'm')
            ->innerJoin('m.tenant', 't')
            ->andWhere('m.id = :id')
            ->andWhere('t.slug = :slug')
            ->setParameter('id', $monitorId)
            ->setParameter('slug', $tenantSlug)
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            if ($row['slug'] === $tenantSlug) {
                return true;
            }
        }

        return false;
    }

    private function select(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s.id AS id', 'm.id AS monitorId', 't.slug AS tenantSlug', 's.userIdentifier AS userIdentifier', 's.createdAt AS createdAt')
            ->from(MonitorShare::class, 's')
            ->innerJoin('s.monitor', 'm')
            ->innerJoin('m.tenant', 't');
    }

    /**
     * @return list<MonitorShareView>
     */
    private function rows(QueryBuilder $qb): array
    {
        return $this->hydrate($qb->getQuery()->getArrayResult());
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<MonitorShareView>
     */
    private function hydrate(array $rows): array
    {
        $views = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_numeric($row['monitorId'] ?? null)
                || !is_string($row['tenantSlug'] ?? null) || !is_string($row['userIdentifier'] ?? null)) {
                continue;
            }
            $createdAt = $row['createdAt'] ?? null;
            $views[]   = new MonitorShareView(
                (int) $row['id'],
                (int) $row['monitorId'],
                $row['tenantSlug'],
                $row['userIdentifier'],
                match (true) {
                    $createdAt instanceof DateTimeImmutable => $createdAt,
                    $createdAt instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($createdAt),
                    default                                 => new DateTimeImmutable(is_string($createdAt) ? $createdAt : '@0'),
                },
            );
        }

        return $views;
    }

    private function runner(): UptimeTransactionRunner
    {
        return $this->transactionRunner ?? new UptimeTransactionRunner($this->entityManager);
    }
}
