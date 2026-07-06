<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\UptimeMonitorBundle\Entity\Tenant;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nowo\UptimeMonitorBundle\Entity\Tenant
 */
final class TenantTest extends TestCase
{
    public function testGettersAndEnabledFlag(): void
    {
        $tenant = new Tenant('main', 'Main');

        self::assertSame('main', $tenant->getSlug());
        self::assertSame('Main', $tenant->getName());
        self::assertTrue($tenant->isEnabled());
        self::assertInstanceOf(DateTimeImmutable::class, $tenant->getCreatedAt());

        $tenant->setEnabled(false);
        self::assertFalse($tenant->isEnabled());
    }
}
