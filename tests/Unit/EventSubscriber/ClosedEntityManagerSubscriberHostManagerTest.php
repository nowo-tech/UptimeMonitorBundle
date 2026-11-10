<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UptimeMonitorBundle\EventSubscriber\ClosedEntityManagerSubscriber;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function dirname;

/**
 * Rule 6 (W0): the subscriber resets only the manager of the bundle entities. A closed host manager is never reset by the
 * bundle. Real ORM: in-memory SQLite entity managers behind a real ManagerRegistry.
 */
final class ClosedEntityManagerSubscriberHostManagerTest extends TestCase
{
    private const BUNDLE = ['Nowo\\UptimeMonitorBundle\\Entity' => __DIR__ . '/../../../src/Entity'];

    public function testClosedHostManagerIsNotResetWhenTheBundleManagerIs(): void
    {
        $registry = new SqliteManagerRegistry(['default' => ['App\\Entity' => dirname(__DIR__, 2)], 'uptime' => self::BUNDLE]);
        $host     = $this->closed($registry, 'default');
        $bundle   = $this->closed($registry, 'uptime');

        (new ClosedEntityManagerSubscriber($registry))->onKernelRequest($this->event());

        self::assertSame($host, $registry->getManager('default'), 'The bundle must never reset a host manager.');
        self::assertFalse($host->isOpen());
        self::assertNotSame($bundle, $registry->getManager('uptime'));
        self::assertTrue($this->manager($registry, 'uptime')->isOpen());
    }

    public function testConfiguredEntityManagerIsTheOnlyOneReset(): void
    {
        $registry = new SqliteManagerRegistry(['default' => self::BUNDLE, 'uptime' => self::BUNDLE]);
        $host     = $this->closed($registry, 'default');
        $this->closed($registry, 'uptime');

        (new ClosedEntityManagerSubscriber($registry, 'uptime'))->onKernelRequest($this->event());

        self::assertSame($host, $registry->getManager('default'));
        self::assertTrue($this->manager($registry, 'uptime')->isOpen());
    }

    public function testDefaultOnlyHostKeepsTheRecovery(): void
    {
        $registry = new SqliteManagerRegistry(['default' => self::BUNDLE]);
        $closed   = $this->closed($registry, 'default');

        (new ClosedEntityManagerSubscriber($registry))->onKernelRequest($this->event());

        self::assertNotSame($closed, $registry->getManager('default'));
        self::assertTrue($this->manager($registry, 'default')->isOpen());
    }

    private function closed(SqliteManagerRegistry $registry, string $name): EntityManagerInterface
    {
        $manager = $this->manager($registry, $name);
        $manager->close();

        return $manager;
    }

    private function manager(SqliteManagerRegistry $registry, string $name): EntityManagerInterface
    {
        $manager = $registry->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function event(): RequestEvent
    {
        $request = new Request();
        $request->attributes->set('_route', 'nowo_uptime_dashboard');

        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
