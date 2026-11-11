<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\EventSubscriber\WorkerBoundaryResetListener;
use Symfony\Contracts\Service\ResetInterface;

use function sprintf;

/**
 * The bundle's own entity manager (1.27.0, REQ-ARCH-001 rule 6 and rule 8).
 *
 * The bundle never flushes, clears, closes or resets an entity manager of the application. Every bundle read and write
 * goes through this **private** manager, created on:
 *
 * - the connection `doctrine.connection` when it is set, else the connection of the manager that maps
 *   {@see Monitor} (`ManagerRegistry::getManagerForClass()`, the default manager of a standard application, where the
 *   `uptime_*` tables already are: nothing moves on upgrade);
 * - the configuration (mapping, metadata cache, naming strategy) and the event manager (table prefix listener) of
 *   `doctrine.entity_manager` when it is set, else of that same manager.
 *
 * Its unit of work holds only bundle rows, so a bundle flush never commits a change the application left pending, and
 * a failed bundle flush closes only this manager. The connection is shared: a transaction the application opened
 * around a bundle call includes the bundle rows.
 *
 * Worker-safe without `kernel.reset` (REQ-RUNTIME-002): a closed inner manager is replaced on the next call, and the
 * worker boundaries ({@see WorkerBoundaryResetListener}) and `kernel.reset`
 * drop it ({@see self::reset()}), so no request, message or command sees the entities of the previous one.
 *
 * The object itself is stable: services and repositories keep it for the life of the container; only the inner
 * manager behind it is replaced. Every method of {@see EntityManagerDecorator} reaches the inner manager through the
 * `wrapped` property, which is never initialised here: {@see self::__get()} answers it with the live inner manager.
 */
final class UptimeEntityManager extends EntityManagerDecorator implements ResetInterface
{
    /** Name of the bundle manager in {@see UptimeManagerRegistry}. */
    public const NAME = 'nowo_uptime_monitor';

    private ?EntityManagerInterface $inner = null;

    /**
     * @param ManagerRegistry $registry Doctrine registry of the application (the source managers are resolved lazily)
     * @param string|null $entityManagerName `doctrine.entity_manager`: the manager whose configuration and event manager
     *                                       are shared (null = the manager that maps the bundle entities)
     * @param string|null $connectionName `doctrine.connection`: the DBAL connection of the bundle tables (null = the
     *                                    connection of the manager that maps the bundle entities)
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ?string $entityManagerName = null,
        private readonly ?string $connectionName = null,
    ) {
        // Every decorator method reads $this->wrapped: left unset, it is answered by __get() with the live manager.
        unset($this->wrapped);
    }

    /**
     * Answers the decorator's `wrapped` property with the live inner manager.
     */
    public function __get(string $name): mixed
    {
        if ($name !== 'wrapped') {
            throw new LogicException(sprintf('Undefined property %s::$%s.', self::class, $name));
        }

        return $this->live();
    }

    /**
     * The open inner manager, created (again) when there is none yet or the previous one was closed by a failed flush.
     */
    public function live(): EntityManagerInterface
    {
        $inner = $this->inner;
        if ($inner instanceof EntityManagerInterface && $inner->isOpen()) {
            return $inner;
        }

        $source       = $this->configurationSource();
        $eventManager = $source->getEventManager();

        // @igor-ignore - Bundle-owned unit of work: dropped at every worker boundary and by kernel.reset, replaced when closed.
        return $this->inner = new EntityManager(
            $this->connection(),
            $source->getConfiguration(),
            // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
            $eventManager instanceof EventManager ? $eventManager : null,
        );
    }

    /**
     * Whether the current inner manager is open. True before the first use and after {@see self::reset()}: the next
     * call creates a fresh one. False only between a failed flush and the next call, so callers can tell that the
     * entities they hold are detached.
     */
    public function isOpen(): bool
    {
        return !$this->inner instanceof EntityManagerInterface || $this->inner->isOpen();
    }

    /**
     * Drops the inner manager (worker boundaries, kernel.reset): its entities are detached, the next call creates a
     * fresh one. Never touches an application manager or the connection.
     */
    public function reset(): void
    {
        $inner       = $this->inner;
        $this->inner = null;
        $inner?->clear();
    }

    /**
     * Whether an inner manager exists (it is created on first use).
     */
    public function isInitialized(): bool
    {
        return $this->inner instanceof EntityManagerInterface;
    }

    /**
     * The given bundle entity as a managed entity of this manager: itself when it is managed here, else the row with
     * the same identifier (an entity the application loaded through its own manager), else itself (a new entity).
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    public function adopt(object $entity): object
    {
        if ($this->contains($entity)) {
            return $entity;
        }

        $metadata = $this->getClassMetadata($entity::class);
        $id       = $metadata->getIdentifierValues($entity);
        if ($id === []) {
            return $entity;
        }

        /** @var T|null $managed */
        $managed = $this->find($metadata->getName(), $id);

        return $managed ?? $entity;
    }

    /**
     * The given bundle entity as an entity of the application manager that maps it (the one `doctrine` returns for
     * the class): what a port hands back to the application, so the application can relate it to its own entities and
     * flush its own manager. Falls back to the entity itself when no application manager maps the class or the row is
     * not visible there.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    public function forApplication(object $entity): object
    {
        $class   = $this->getClassMetadata($entity::class)->getName();
        $manager = $this->registry->getManagerForClass($class);
        if (!$manager instanceof EntityManagerInterface || $manager->contains($entity)) {
            return $entity;
        }

        $id = $this->getClassMetadata($class)->getIdentifierValues($entity);
        if ($id === []) {
            return $entity;
        }

        /** @var T|null $copy */
        $copy = $manager->find($class, $id);

        return $copy ?? $entity;
    }

    /**
     * The application manager that maps the bundle entities (`getManagerForClass(Monitor)`).
     */
    public function mappingManager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(Monitor::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine ORM entity manager maps %s: register the UptimeMonitorBundle mapping (nowo_uptime_monitor.doctrine.register_mapping) or map the bundle entities on a manager of the application.', Monitor::class));
        }

        return $manager;
    }

    /**
     * The application manager whose configuration and event manager the bundle manager shares.
     */
    private function configurationSource(): EntityManagerInterface
    {
        if ($this->entityManagerName === null) {
            return $this->mappingManager();
        }

        $manager = $this->registry->getManager($this->entityManagerName);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('Entity manager "%s" (nowo_uptime_monitor.doctrine.entity_manager) is not a Doctrine ORM entity manager.', $this->entityManagerName));
        }

        return $manager;
    }

    /**
     * The connection of the bundle tables.
     */
    private function connection(): Connection
    {
        if ($this->connectionName === null) {
            return $this->mappingManager()->getConnection();
        }

        $connection = $this->registry->getConnection($this->connectionName);
        if (!$connection instanceof Connection) {
            throw new LogicException(sprintf('Connection "%s" (nowo_uptime_monitor.doctrine.connection) is not a Doctrine DBAL connection.', $this->connectionName));
        }

        return $connection;
    }
}
