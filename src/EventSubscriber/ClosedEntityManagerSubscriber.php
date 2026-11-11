<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_search;
use function in_array;
use function is_string;
use function str_starts_with;

/**
 * Replaces a closed EntityManager before a bundle route runs.
 *
 * When the kernel is not reset between requests (long-running workers without `services_resetter`),
 * a failed flush in a previous request leaves the manager closed for every later request of that worker.
 * Only the bundle's managers are reset (1.26.0), and only when closed: the manager of the bundle entities
 * (getManagerForClass(), always included) and `doctrine.entity_manager` when set. Host managers, closed or open, are
 * never touched.
 */
final readonly class ClosedEntityManagerSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_uptime_';

    /**
     * Creates the subscriber for the bundle's Doctrine manager.
     *
     * @param ManagerRegistry $registry Doctrine registry
     * @param string|null $entityManagerName `doctrine.entity_manager` (null: the manager of the bundle entities)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $entityManagerName = null,
    ) {
    }

    /**
     * Listens on kernel.request right after the router resolved the route.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // After RouterListener (32) so that `_route` is known.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 31],
        ];
    }

    /**
     * Resets the bundle's entity manager, when closed, before a bundle route (`nowo_uptime_*`) of the main request runs.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        foreach ($this->bundleManagerNames() as $name) {
            $manager = $this->registry->getManager($name);
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->registry->resetManager($name);
            }
        }
    }

    /**
     * The bundle's managers (1.26.0, audit LD-UM-M1): the manager of the bundle entities (getManagerForClass(Monitor),
     * always included: the bundle maps and writes them there) plus `doctrine.entity_manager` when set.
     *
     * @return list<string>
     */
    private function bundleManagerNames(): array
    {
        $names   = $this->entityManagerName !== null ? [$this->entityManagerName] : [];
        $manager = $this->registry->getManagerForClass(Monitor::class);
        $name    = $manager instanceof ObjectManager ? array_search($manager, $this->registry->getManagers(), true) : false;
        if (is_string($name) && !in_array($name, $names, true)) {
            $names[] = $name;
        }

        return $names;
    }
}
