<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Support;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use LogicException;

use function array_combine;
use function array_keys;

/**
 * A ManagerRegistry over fixed managers and connections, for the edge cases a real registry cannot produce (no
 * manager for a class, a non-ORM manager, a non-DBAL connection). Resets are recorded, never performed.
 */
final class StubManagerRegistry implements ManagerRegistry
{
    /** @var list<string|null> */
    public array $resets = [];

    /**
     * @param array<string, ObjectManager> $managers
     * @param array<string, object> $connections
     * @param ObjectManager|null $forClass what getManagerForClass() answers for every class
     */
    public function __construct(
        private readonly array $managers = [],
        private readonly array $connections = [],
        private readonly ?ObjectManager $forClass = null,
    ) {
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection(?string $name = null): object
    {
        return $this->connections[$name ?? 'default'] ?? throw new LogicException('no connection');
    }

    public function getConnections(): array
    {
        return $this->connections;
    }

    public function getConnectionNames(): array
    {
        $names = array_keys($this->connections);

        return array_combine($names, $names);
    }

    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    public function getManager(?string $name = null): ObjectManager
    {
        return $this->managers[$name ?? 'default'] ?? throw new LogicException('no manager');
    }

    public function getManagers(): array
    {
        return $this->managers;
    }

    public function resetManager(?string $name = null): ObjectManager
    {
        $this->resets[] = $name;

        return $this->getManager($name);
    }

    public function getManagerNames(): array
    {
        $names = array_keys($this->managers);

        return array_combine($names, $names);
    }

    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        return $this->getManager($persistentManagerName)->getRepository($persistentObject);
    }

    public function getManagerForClass(string $class): ?ObjectManager
    {
        return $this->forClass;
    }
}
