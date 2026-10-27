<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use Nowo\UptimeMonitorBundle\Repository\TenantRepository;
use Nowo\UptimeMonitorBundle\Service\TenantProvisioner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantProvisioner::class)]
final class TenantProvisionerTest extends TestCase
{
    public function testEnsureCreatesOnceAndKeepsAnExistingTenant(): void
    {
        $existing = new Tenant('known', 'Known');
        $tenants  = $this->createMock(TenantRepository::class);
        $tenants->method('findOneBySlug')->willReturnCallback(static fn (string $slug): ?Tenant => $slug === 'known' ? $existing : null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(Tenant::class));
        $entityManager->expects(self::once())->method('flush');
        $provisioner = new TenantProvisioner($tenants, $entityManager);

        $created = $provisioner->ensure('new-one', 'New one');
        self::assertSame('new-one', $created->getSlug());
        self::assertSame('New one', $created->getName());
        self::assertSame($existing, $provisioner->ensure('known', 'Renamed?'));
        self::assertSame('Known', $existing->getName());
    }

    public function testSetEnabled(): void
    {
        $tenant  = new Tenant('known', 'Known');
        $tenants = $this->createMock(TenantRepository::class);
        $tenants->method('findOneBySlug')->willReturnCallback(static fn (string $slug): ?Tenant => $slug === 'known' ? $tenant : null);
        $provisioner = new TenantProvisioner($tenants, $this->createMock(EntityManagerInterface::class));

        self::assertTrue($provisioner->setEnabled('known', false));
        self::assertFalse($tenant->isEnabled());
        self::assertFalse($provisioner->setEnabled('ghost', true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugProvider(): iterable
    {
        yield 'mercure template selector' => ['{tenant}'];
        yield 'uppercase' => ['Acme'];
        yield 'path' => ['a/b'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidSlugProvider')]
    public function testEnsureRejectsSlugsTheTenantFormWouldRefuse(string $slug): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $this->expectException(InvalidArgumentException::class);

        (new TenantProvisioner($this->createStub(TenantRepository::class), $entityManager))->ensure($slug, 'Name');
    }
}
