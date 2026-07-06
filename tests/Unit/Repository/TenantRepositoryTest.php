<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Repository\TenantRepository
 */
final class TenantRepositoryTest extends TestCase
{
    public function testFindOneBySlugDelegatesToFindOneBy(): void
    {
        $tenant = new Tenant('main', 'Main');

        $repository = $this->getMockBuilder(TenantRepository::class)
            ->onlyMethods(['findOneBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findOneBy')->with(['slug' => 'main'])->willReturn($tenant);

        self::assertSame($tenant, $repository->findOneBySlug('main'));
    }

    public function testConstructorAcceptsRegistry(): void
    {
        $em       = $this->createMock(EntityManagerInterface::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        $repository = new TenantRepository($registry);
        self::assertInstanceOf(TenantRepository::class, $repository);
    }
}
