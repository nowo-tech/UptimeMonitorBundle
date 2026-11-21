<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeEntityManager;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Form\TenantFormType;
use Nowo\UptimeMonitorBundle\Query\MonitorListCriteria;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Transaction\UptimeTransactionRunner;

use function in_array;
use function preg_match;
use function spl_object_id;
use function sprintf;

/**
 * Host-managed tenants (since 1.9): a multi-tenant host creates, renames and disables its tenants itself — e.g. one per
 * project, slug = the project id — instead of the `/tenants` UI (set `tenants.list_enabled: false`). Since 1.25 host code
 * type-hints {@see TenantProvisionerInterface}.
 */
final readonly class TenantProvisioner implements BulkTenantProvisionerInterface
{
    /**
     * Creates the provisioner.
     *
     * @param TenantRepository $tenants Tenant lookup by slug
     * @param EntityManagerInterface $entityManager Persists created and changed tenants (the bundle's private manager
     *                                              since 1.27.0: an application manager is never flushed)
     * @param UptimeTransactionRunner|null $transactionRunner The bundle's runner (since 1.29; null = one on $entityManager)
     */
    public function __construct(
        private TenantRepository $tenants,
        private EntityManagerInterface $entityManager,
        private ?UptimeTransactionRunner $transactionRunner = null,
    ) {
    }

    /**
     * The tenant with this slug, created (enabled) when missing; an existing tenant keeps its settings.
     *
     * Since 1.29 (Beacon spec 239) two concurrent calls for one new slug (two first visits of a project) both get the
     * tenant: the one whose insert the unique slug refuses reads the row the other wrote, instead of failing with a
     * unique violation (a 500 before).
     *
     * @throws InvalidArgumentException when the slug is not lowercase letters, digits and hyphens
     */
    public function ensure(string $slug, string $name): Tenant
    {
        // Same rule as the tenant form: the slug ends up in routes and Mercure topics (`{`/`}` are URI-template syntax).
        if (preg_match(TenantFormType::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid tenant slug "%s": use lowercase letters, digits and hyphens.', $slug));
        }

        $tenant = $this->tenants->findOneBySlug($slug);
        if (!$tenant instanceof Tenant) {
            try {
                // 1.27.0: the bundle's private manager, so only this tenant is written (never the application's changes).
                $tenant = $this->runner()->run(function () use ($slug, $name): Tenant {
                    $tenant = new Tenant($slug, $name);
                    $this->entityManager->persist($tenant);

                    return $tenant;
                });
            } catch (UniqueConstraintViolationException $lost) {
                // Another request created it first: its row is the tenant.
                $tenant = $this->tenants->findOneBySlug($slug) ?? throw $lost;
            }
        }

        // The application gets the tenant of its own manager (it may relate its entities to it and flush them).
        return $this->entityManager instanceof UptimeEntityManager ? $this->entityManager->forApplication($tenant) : $tenant;
    }

    /** Enables or disables a tenant (a disabled tenant's checks stop); false when the slug is unknown. */
    public function setEnabled(string $slug, bool $enabled): bool
    {
        $tenant = $this->tenants->findOneBySlug($slug);
        if (!$tenant instanceof Tenant) {
            return false;
        }
        $this->runner()->run(static fn (): Tenant => $tenant->setEnabled($enabled));

        return true;
    }

    /**
     * Enables or disables the listed tenants in one statement (since 1.35, Beacon spec 225).
     *
     * The `UPDATE` bypasses the unit of work: the tenants the bundle's manager already holds for these slugs get the new
     * value in memory, as their committed state (no second write), so a later read in the same request sees it and a
     * managed monitor of those tenants still flushes. An application manager is never
     * touched; tenants the application loaded through its own manager keep their old `enabled` value until reloaded.
     *
     * Inside a transaction the caller opened, the statement joins it (a savepoint): if the caller then rolls back, the
     * rows keep their old value but the copies the bundle's manager holds keep the new one until that manager is
     * dropped (the next worker boundary) or the tenant is reloaded (audit XZD-I1). Since 1.36 a lock wait or deadlock
     * of a call outside a transaction is replayed by the runner (the statement is idempotent) before it surfaces as a
     * TransientStorageFailure (CONFLICT_001); inside a caller's transaction it surfaces at once (audit XZD-I2).
     *
     * @param list<string> $slugs Tenant slugs (invalid ones dropped, duplicates ignored)
     */
    public function setEnabledMany(array $slugs, bool $enabled): int
    {
        $slugs = (new MonitorListCriteria($slugs))->tenantSlugs;
        if ($slugs === []) {
            return 0;
        }

        $manager = $this->entityManager;
        $changed = $this->runner()->runRetrying(static fn (): int => (int) $manager->createQueryBuilder()
            ->update(Tenant::class, 't')
            ->set('t.enabled', ':enabled')
            ->andWhere('t.slug IN (:slugs)')
            ->setParameter('enabled', $enabled)
            ->setParameter('slugs', $slugs)
            ->getQuery()
            ->execute());

        // The copies the bundle's manager holds take the new value as their committed state (never detached: a managed
        // monitor of that tenant would then point to a "new" tenant on the next flush). An uninitialised proxy loads
        // the new value itself; other pending changes of the tenant stay pending.
        $unitOfWork = $manager->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap()[Tenant::class] ?? [] as $tenant) {
            if (!$tenant instanceof Tenant || $unitOfWork->isUninitializedObject($tenant) || !in_array($tenant->getSlug(), $slugs, true)) {
                continue;
            }
            $tenant->setEnabled($enabled);
            // @igor-ignore - Bundle-owned unit of work, dropped at every worker boundary; the value equals the committed row.
            $unitOfWork->setOriginalEntityProperty(spl_object_id($tenant), 'enabled', $enabled);
        }

        return $changed;
    }

    /**
     * The bundle's runner of every write (since 1.29).
     */
    private function runner(): UptimeTransactionRunner
    {
        return $this->transactionRunner ?? new UptimeTransactionRunner($this->entityManager);
    }
}
