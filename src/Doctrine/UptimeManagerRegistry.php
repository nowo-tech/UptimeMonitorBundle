<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Doctrine;

use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;

use function str_contains;
use function str_starts_with;

/**
 * The bundle's view of the Doctrine registry (1.27.0, REQ-ARCH-001 rule 6).
 *
 * Bundle services and the bundle's internal repositories receive this registry instead of `doctrine`: for a bundle
 * entity class it answers {@see UptimeEntityManager} (the bundle's private manager), registered as
 * {@see UptimeEntityManager::NAME}; resetting that name drops the private manager only. Every other class, name and
 * connection is answered by the application registry, and no application manager is ever reset through it.
 */
final readonly class UptimeManagerRegistry implements ManagerRegistry
{
    /** Namespace of the bundle entities. */
    public const ENTITY_NAMESPACE = 'Nowo\\UptimeMonitorBundle\\Entity\\';

    /**
     * @param ManagerRegistry $registry The application registry (`doctrine`)
     * @param UptimeEntityManager $entityManager The bundle's private manager
     */
    public function __construct(
        private ManagerRegistry $registry,
        private UptimeEntityManager $entityManager,
    ) {
    }

    /**
     * The application's default connection name.
     */
    public function getDefaultConnectionName(): string
    {
        return $this->registry->getDefaultConnectionName();
    }

    /**
     * An application connection.
     */
    public function getConnection(?string $name = null): object
    {
        return $this->registry->getConnection($name);
    }

    /**
     * The application connections.
     *
     * @return array<string, object>
     */
    public function getConnections(): array
    {
        return $this->registry->getConnections();
    }

    /**
     * The application connection names.
     *
     * @return array<string, string>
     */
    public function getConnectionNames(): array
    {
        return $this->registry->getConnectionNames();
    }

    /**
     * The application's default manager name.
     */
    public function getDefaultManagerName(): string
    {
        return $this->registry->getDefaultManagerName();
    }

    /**
     * The bundle manager for {@see UptimeEntityManager::NAME}, else the application manager of that name.
     */
    public function getManager(?string $name = null): ObjectManager
    {
        return $name === UptimeEntityManager::NAME ? $this->entityManager : $this->registry->getManager($name);
    }

    /**
     * The application managers plus the bundle manager.
     *
     * @return array<string, ObjectManager>
     */
    public function getManagers(): array
    {
        return $this->registry->getManagers() + [UptimeEntityManager::NAME => $this->entityManager];
    }

    /**
     * Drops the bundle manager for {@see UptimeEntityManager::NAME}; any other name is the application's business, so it
     * is returned untouched (the bundle never resets an application manager).
     */
    public function resetManager(?string $name = null): ObjectManager
    {
        if ($name === UptimeEntityManager::NAME) {
            $this->entityManager->reset();

            return $this->entityManager;
        }

        return $this->registry->getManager($name);
    }

    /**
     * The application manager names plus the bundle manager.
     *
     * @return array<string, string>
     */
    public function getManagerNames(): array
    {
        return $this->registry->getManagerNames() + [UptimeEntityManager::NAME => UptimeEntityManager::class];
    }

    /**
     * The repository of a bundle entity on the bundle manager, else the application's.
     *
     * @template T of object
     *
     * @param class-string<T> $persistentObject
     *
     * @return ObjectRepository<T>
     */
    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        if ($persistentManagerName === UptimeEntityManager::NAME || ($persistentManagerName === null && $this->isBundleClass($persistentObject))) {
            /** @var EntityRepository<T> $repository */
            $repository = $this->entityManager->getRepository($persistentObject);

            return $repository;
        }

        return $this->registry->getRepository($persistentObject, $persistentManagerName);
    }

    /**
     * The bundle manager for a bundle entity, else the application manager that maps the class.
     */
    public function getManagerForClass(string $class): ?ObjectManager
    {
        return $this->isBundleClass($class) ? $this->entityManager : $this->registry->getManagerForClass($class);
    }

    private function isBundleClass(string $class): bool
    {
        return str_starts_with($class, self::ENTITY_NAMESPACE)
            || str_contains($class, '\\__CG__\\' . self::ENTITY_NAMESPACE);
    }
}
