<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Search;

use Nowo\UptimeMonitorBundle\Event\MonitorSearchScopeEvent;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchTenantResolverInterface;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchTenants;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchTenantsResolverInterface;
use Nowo\UptimeMonitorBundle\Search\MonitorSearchVisibility;
use Nowo\UptimeMonitorBundle\Security\SearchAwareUptimeMonitorAccessCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function in_array;

/**
 * 1.34: the tenants of a monitor search — a list resolver (or a 1.32 single-tenant one), the scope event (narrowing
 * only), the scope of the search box (never widening) and the search permission per tenant.
 */
#[CoversClass(MonitorSearchTenants::class)]
#[CoversClass(MonitorSearchScopeEvent::class)]
final class MonitorSearchTenantsTest extends TestCase
{
    /** @var list<string> */
    private array $searchable = ['acme', 'beta', 'gamma', '123'];

    /**
     * @param list<mixed> $keys
     */
    private function lister(array $keys): MonitorSearchTenantsResolverInterface
    {
        return new class($keys) implements MonitorSearchTenantsResolverInterface {
            /** @param list<mixed> $keys */
            public function __construct(private readonly array $keys)
            {
            }

            public function resolveTenants(string $userIdentifier): array
            {
                /* @var list<string> */
                return $this->keys;
            }
        };
    }

    private function tenants(MonitorSearchTenantResolverInterface|MonitorSearchTenantsResolverInterface $resolver, ?EventDispatcher $dispatcher = null): MonitorSearchTenants
    {
        $checker = $this->createStub(SearchAwareUptimeMonitorAccessCheckerInterface::class);
        $checker->method('canSearch')->willReturnCallback(fn (object $user, string $slug): bool => in_array($slug, $this->searchable, true));

        return new MonitorSearchTenants($resolver, new MonitorSearchVisibility($checker), $dispatcher);
    }

    public function testTheUnionOfValidKeysIsSearchedOnceEachAndExactly(): void
    {
        $tenants = $this->tenants($this->lister(['acme', 'beta', 'acme', 'ACME', 'acme ', '', 'gamma', 'delta', '123', 7]));

        self::assertSame(['acme', 'beta', 'gamma', '123'], $tenants->resolve('alice'), 'invalid keys dropped, delta has no search permission');
        self::assertSame([], $this->tenants($this->lister([]))->resolve('alice'), 'an empty list means nothing');
    }

    public function testASingleTenantResolverStillWorks(): void
    {
        $single = new class implements MonitorSearchTenantResolverInterface {
            public ?string $slug = 'beta';

            public function resolve(string $userIdentifier): ?string
            {
                return $this->slug;
            }
        };

        self::assertSame(['beta'], $this->tenants($single)->resolve('alice'));
        $single->slug = null;
        self::assertSame([], $this->tenants($single)->resolve('alice'));
    }

    public function testTheScopeEventOnlyNarrows(): void
    {
        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorSearchScopeEvent::class, static function (MonitorSearchScopeEvent $event) use (&$events): void {
            $events[] = [$event->userIdentifier, $event->getResolvedTenants(), $event->getScope()];
            $event->onlyTenants(['acme', 'beta', 'zeta', '']);
            $event->onlyTenants(['beta', 'acme', '123']);
            $event->excludeTenants(['beta']);
        });

        self::assertSame(['acme'], $this->tenants($this->lister(['acme', 'beta', 'gamma', '123']), $dispatcher)->resolve('alice'));
        self::assertSame([['alice', ['acme', 'beta', 'gamma', '123'], null]], $events);

        $deny = new EventDispatcher();
        $deny->addListener(MonitorSearchScopeEvent::class, static function (MonitorSearchScopeEvent $event): void {
            $event->deny();
        });
        self::assertSame([], $this->tenants($this->lister(['acme']), $deny)->resolve('alice'));

        $numeric = new EventDispatcher();
        $numeric->addListener(MonitorSearchScopeEvent::class, static function (MonitorSearchScopeEvent $event): void {
            $event->onlyTenants(['123']);
        });
        self::assertSame(['123'], $this->tenants($this->lister(['acme', '123']), $numeric)->resolve('alice'), 'numeric keys stay strings');
    }

    public function testTheScopeOfTheSearchBoxNeverWidens(): void
    {
        $tenants = $this->tenants($this->lister(['acme', 'beta']));

        self::assertSame(['beta'], $tenants->resolve('alice', 'tenant:beta'));
        self::assertSame([], $tenants->resolve('alice', 'tenant:gamma'), 'a tenant outside the list');
        self::assertSame([], $tenants->resolve('alice', 'project:p-1'), 'a scope no listener accepted');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MonitorSearchScopeEvent::class, static function (MonitorSearchScopeEvent $event): void {
            if ($event->getScope() === 'project:p-1') {
                $event->onlyTenants(['beta', 'gamma']);
                $event->acceptScope();
            }
            self::assertSame($event->getScope() === 'project:p-1', $event->isScopeAccepted());
        });
        self::assertSame(['beta'], $this->tenants($this->lister(['acme', 'beta']), $dispatcher)->resolve('alice', 'project:p-1'));
    }
}
