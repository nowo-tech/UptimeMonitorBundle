<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Doctrine\UptimeRowLock;
use Nowo\UptimeMonitorBundle\Entity\Monitor;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Repository\MonitorRepository;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Tests\Support\SqliteEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UptimeRowLock::class)]
#[CoversClass(MonitorRepository::class)]
#[CoversClass(TenantRepository::class)]
final class UptimeRowLockTest extends TestCase
{
    public function testTheLockedRowOverwritesTheStateTheManagerHeld(): void
    {
        $em     = SqliteEntityManagerFactory::create();
        $tenant = new Tenant('main', 'Main');
        $em->persist($tenant);
        $monitor = new Monitor($tenant, 'API', MonitorType::Https, 'https://api.test');
        $em->persist($monitor);
        $em->flush();
        $em->getConnection()->update('uptime_monitor', ['name' => 'Renamed'], ['id' => $monitor->getId()]);
        $em->getConnection()->update('uptime_tenant', ['name' => 'Other'], ['id' => $tenant->getId()]);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        $em->getConnection()->beginTransaction();
        $locked       = (new MonitorRepository($registry))->lockForWrite((int) $monitor->getId());
        $lockedTenant = (new TenantRepository($registry))->lockForWrite((int) $tenant->getId());
        $missing      = UptimeRowLock::lock($em, Monitor::class, 999);
        $em->getConnection()->commit();

        self::assertSame($monitor, $locked);
        self::assertSame('Renamed', $monitor->getName());
        self::assertSame($tenant, $lockedTenant);
        self::assertSame('Other', $tenant->getName());
        self::assertNull($missing);
    }

    public function testADueMonitorIsClaimedOnceWhateverItsNextCheckTime(): void
    {
        $em     = SqliteEntityManagerFactory::create();
        $tenant = new Tenant('main', 'Main');
        $em->persist($tenant);
        $never = new Monitor($tenant, 'Never checked', MonitorType::Https, 'https://a.test');
        $never->setNextCheckAt(null);
        $due = new Monitor($tenant, 'Due', MonitorType::Https, 'https://b.test');
        $due->setNextCheckAt(new DateTimeImmutable('2026-03-10 11:00:00'));
        $em->persist($never);
        $em->persist($due);
        $em->flush();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $monitors = new MonitorRepository($registry);
        $lease    = new DateTimeImmutable('2026-03-10 12:01:00');

        self::assertTrue($monitors->claimDue((int) $never->getId(), null, $lease));
        self::assertFalse($monitors->claimDue((int) $never->getId(), null, $lease), 'claimed already');
        self::assertTrue($monitors->claimDue((int) $due->getId(), $due->getNextCheckAt(), $lease));
        self::assertFalse($monitors->claimDue((int) $due->getId(), $due->getNextCheckAt(), $lease), 'claimed already');
        $em->clear();
        self::assertEquals($lease, $em->find(Monitor::class, $never->getId())?->getNextCheckAt());

        // Audit QK-L3: a monitor whose committed next check is in the future is not due, even when the caller read that
        // value (a monitor reloaded, or a group refreshed earlier in the same scan).
        $tenant = $em->find(Tenant::class, $tenant->getId());
        self::assertInstanceOf(Tenant::class, $tenant);
        $future = new Monitor($tenant, 'Not due', MonitorType::Https, 'https://c.test');
        $future->setNextCheckAt(new DateTimeImmutable('2026-03-10 12:05:00'));
        $em->persist($future);
        $em->flush();
        $scan = new DateTimeImmutable('2026-03-10 12:00:00');
        self::assertFalse($monitors->claimDue((int) $future->getId(), $future->getNextCheckAt(), $lease, $scan));
        self::assertTrue($monitors->claimDue((int) $future->getId(), $future->getNextCheckAt(), $lease, $future->getNextCheckAt()));
    }
}
