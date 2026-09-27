<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;
use function str_starts_with;

/**
 * Replaces a closed EntityManager before a bundle route runs.
 *
 * When the kernel is not reset between requests (long-running workers without `services_resetter`),
 * a failed flush in a previous request leaves the manager closed for every later request of that worker.
 * Only closed managers are reset; open managers and their identity maps are left untouched.
 */
final readonly class ClosedEntityManagerSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_uptime_';

    public function __construct(
        private ManagerRegistry $registry,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After RouterListener (32) so that `_route` is known.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 31],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        foreach ($this->registry->getManagers() as $name => $manager) {
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->registry->resetManager($name);
            }
        }
    }
}
