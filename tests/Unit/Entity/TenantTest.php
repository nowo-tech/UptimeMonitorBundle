<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Entity;

use Nowo\UptimeMonitorBundle\Entity\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Tenant::class)]
final class TenantTest extends TestCase
{
    public function testGettersAndEnabledFlag(): void
    {
        $tenant = new Tenant('main', 'Main');

        self::assertSame('main', $tenant->getSlug());
        self::assertSame('Main', $tenant->getName());
        self::assertTrue($tenant->isEnabled());
        self::assertGreaterThan(0, $tenant->getCreatedAt()->getTimestamp());

        $tenant->setEnabled(false);
        self::assertFalse($tenant->isEnabled());
    }
}
